<?php
declare(strict_types=1);

namespace Routlaw\Security;

/**
 * Authorization: T2 * tenant+role enforcement (FR-003/SEC-010).
 *
 * Every protected request must pass tenant-scope AND role-permission checks
 * before data is returned. Frontend hiding is never authorization.
 *
 * No Python, no Redis, no Docker — pure PHP 8.3 on MariaDB.
 */
final class Authorization
{
    private \mysqli $db;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Check whether a user may access a given tenant (company).
     *
     * Tenant scope is mandatory from day one (FR-042, BR-020). A user
     * may only access their own tenant's resources.
     *
     * @param int $companyId The tenant scope being accessed.
     * @param int $userId    The authenticated user (0 = anonymous).
     * @return bool True if the user belongs to this tenant.
     */
    public function canAccessTenant(int $companyId, int $userId): bool
    {
        if ($userId <= 0) {
            $this->auditDenial(0, null, 'tenant_access', 'anonymous');
            return false;
        }

        $stmt = $this->db->prepare('SELECT company_id FROM users WHERE id = ?');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();

        if ($row === null) {
            $this->auditDenial(0, $userId, 'tenant_access', 'unknown_user');
            return false;
        }

        $userCompany = (int) $row['company_id'];
        if ($userCompany !== $companyId) {
            $this->auditDenial($userCompany, $userId, 'tenant_access', 'cross_tenant');
            return false;
        }

        return true;
    }

    /**
     * Check whether a user has a specific permission in their tenant.
     *
     * Server-side enforcement of role-based access control (FR-003, SEC-010).
     * Permissions are resolved through the user's role -> role_permissions.
     *
     * @param int    $userId       The authenticated user.
     * @param int    $companyId    The tenant scope.
     * @param string $permissionCode The permission code (e.g. 'users.manage').
     */
    public function hasPermission(int $userId, int $companyId, string $permissionCode): bool
    {
        if ($userId <= 0) {
            $this->auditDenial($companyId, $userId, 'permission_denied', 'anonymous');
            return false;
        }

        // First verify tenant scope, then check permission.
        if (!$this->canAccessTenant($companyId, $userId)) {
            // canAccessTenant already audited the denial.
            return false;
        }

        // Look up the permission by code.
        $stmt = $this->db->prepare(
            'SELECT p.id FROM permissions p WHERE p.code = ?'
        );
        $stmt->bind_param('s', $permissionCode);
        $stmt->execute();
        $result = $stmt->get_result();
        $permRow = $result->fetch_assoc();
        $stmt->close();

        if ($permRow === null) {
            $this->auditDenial($companyId, $userId, 'permission_unknown', $permissionCode);
            return false;
        }
        $permissionId = (int) $permRow['id'];

        // Check: does the user's role have this permission?
        // The user's role_id is stored on the users table; we verify the role
        // belongs to the same tenant scope via user_role_assignments.
        $stmt = $this->db->prepare(
            'SELECT 1 FROM user_role_assignments ura '
            . 'JOIN role_permissions rp ON ura.role_id = rp.role_id '
            . 'WHERE ura.user_id = ? AND ura.company_id = ? AND rp.permission_id = ? '
            . 'LIMIT 1'
        );
        $stmt->bind_param('iii', $userId, $companyId, $permissionId);
        $stmt->execute();
        $result = $stmt->get_result();
        $has = $result->fetch_assoc() !== null;
        $stmt->close();

        if (!$has) {
            $this->auditDenial($companyId, $userId, 'permission_denied', $permissionCode);
        }

        return $has;
    }

    /**
     * Execute a tenant-scoped query with user authorization.
     *
     * The caller provides SQL with a ? placeholder for company_id as the
     * FIRST parameter. This method:
     * 1. Verifies the requesting user may access the requested tenant (FR-003, SEC-010).
     * 2. Enforces tenant scoping at the data layer so no cross-tenant rows
     *    can ever be returned.
     *
     * @param int         $userId    The requesting authenticated user (0 = anonymous).
     * @param int         $companyId The tenant scope enforced on the query.
     * @param string      $table     The table being queried (for audit).
     * @param string      $sql       SQL with company_id as first ? placeholder.
     * @param list<mixed> $params    Additional params after company_id.
     * @return list<array<string,mixed>> Tenant-scoped result rows (empty if denied).
     */
    public function scopeQuery(int $userId, int $companyId, string $table, string $sql, array $params = []): array
    {
        // Enforce tenant authorization before touching data (FR-003, SEC-010).
        if (!$this->canAccessTenant($companyId, $userId)) {
            return [];
        }

        // Prepend company_id as the first bound parameter.
        array_unshift($params, $companyId);

        // Build a dynamic bind_param type string from the values.
        $types = '';
        foreach ($params as $val) {
            if (is_int($val)) {
                $types .= 'i';
            } elseif (is_float($val)) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }

        $stmt = $this->db->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare scoped query: ' . $this->db->error);
        }

        // mysqli::bind_param requires pass-by-reference; the spread operator
        // passes by value, so we build references explicitly.
        $bindParams = [$types];
        foreach ($params as $key => $value) {
            $bindParams[] = &$params[$key];
        }
        $stmt->bind_param(...$bindParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return $rows;
    }

    /**
     * Write a denial event to the audit log (FR-029).
     */
    private function auditDenial(int $companyId, ?int $userId, string $action, string $target): void
    {
        $actorType = $userId !== null && $userId > 0 ? 'user' : 'anonymous';
        $actorId = $userId ?? 0;
        $detail = json_encode(
            ['target' => $target],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        $stmt = $this->db->prepare(
            'INSERT INTO audit_events (company_id, actor_type, actor_id, event_type, action, target_type, target_id, result, detail) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $eventType = 'authz.denied';
        $targetType = $action;
        $targetId = $target;
        $result = 'failure';
        $stmt->bind_param(
            'issssssss',
            $companyId,
            $actorType,
            $actorId,
            $eventType,
            $action,
            $targetType,
            $targetId,
            $result,
            $detail
        );
        $stmt->execute();
        $stmt->close();
    }
}
