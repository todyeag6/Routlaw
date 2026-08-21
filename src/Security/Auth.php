<?php
declare(strict_types=1);

namespace Routlaw\Security;

/**
 * Auth: T1 authentication service (FR-001/FR-002/SEC-002).
 *
 * - Argon2id password hashing (FR-002).
 * - Login establishes a role/tenant-scoped session (FR-001, SEC-002/SEC-010).
 * - Repeated failures are rate-limited (FR-001).
 * - Auth events are written to audit_events (FR-029).
 *
 * No Python, no Redis, no Docker — pure PHP 8.3 on MariaDB.
 */
final class Auth
{
    private \mysqli $db;

    /** Max failed attempts before lockout (FR-001 rate-limit). */
    public const MAX_LOGIN_ATTEMPTS = 5;

    /** Lockout window in seconds (15 minutes). */
    public const LOCKOUT_SECONDS = 900;

    public function __construct(\mysqli $db)
    {
        $this->db = $db;
    }

    /**
     * Hash a password using Argon2id (FR-002).
     * Uses PHP's password_hash with PASSWORD_ARGON2ID and default cost options.
     */
    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    /**
     * Verify a password against an Argon2id hash (FR-002).
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        if ($hash === '' || $hash === '0') {
            return false;
        }
        return password_verify($password, $hash);
    }

    /**
     * Create a company (tenant) record. Returns company_id.
     * Tenant scope is mandatory from day one (FR-042, BR-020).
     */
    public function createCompany(string $displayName, string $legalName): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO companies (legal_name, display_name, status) VALUES (?, ?, ?)'
        );
        $status = 'active';
        $stmt->bind_param('sss', $legalName, $displayName, $status);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to create company: ' . $this->db->error);
        }
        $id = (int) $this->db->insert_id;
        $stmt->close();
        return $id;
    }

    /**
     * Create a user with Argon2id-hashed password and a role assignment.
     * Returns user_id.
     */
    public function createUser(int $companyId, string $email, string $password, string $fullName, int $roleId): int
    {
        $hash = self::hashPassword($password);
        $stmt = $this->db->prepare(
            'INSERT INTO users (company_id, email, password_hash, full_name, status, role_id) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $status = 'active';
        $stmt->bind_param('issssi', $companyId, $email, $hash, $fullName, $status, $roleId);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to create user: ' . $this->db->error);
        }
        $userId = (int) $this->db->insert_id;
        $stmt->close();

        // Assign the user's role (tenant-scoped).
        $stmt = $this->db->prepare(
            'INSERT INTO user_role_assignments (user_id, role_id, company_id) VALUES (?, ?, ?)'
        );
        $stmt->bind_param('iii', $userId, $roleId, $companyId);
        if (!$stmt->execute()) {
            // Roll back the user INSERT if role assignment fails.
            $this->db->query('DELETE FROM users WHERE id = ' . $userId);
            $stmt->close();
            throw new \RuntimeException('Failed to assign role: ' . $this->db->error);
        }
        $stmt->close();

        return $userId;
    }

    /**
     * Authenticate a user by email+password.
     *
     * FR-001: Successful login establishes a role/tenant-scoped session.
     * FR-001: Repeated failures are rate-limited.
     * FR-001: Auth events are logged.
     * SEC-002: Session cookie Secure/HttpOnly/SameSite, ID rotation.
     *
     * Returns a UserSession object on success, null on failure or rate-limit.
     */
    public function login(string $email, string $password): ?UserSession
    {
        // Rate-limit check (FR-001).
        if ($this->isRateLimited($email)) {
            $this->audit(null, null, 'auth.login', 'rate_limited', 'failure', null);
            return null;
        }

        $row = $this->findUserByEmail($email);
        if ($row === null) {
            $this->recordFailure($email);
            $this->audit(null, null, 'auth.login', 'unknown_user', 'failure', null);
            return null;
        }

        if (!self::verifyPassword($password, $row['password_hash'])) {
            $this->recordFailure($email);
            $this->audit((int) $row['id'], (int) $row['company_id'], 'auth.login', 'bad_password', 'failure', null);
            return null;
        }

        // Success: clear any prior failed-attempt records for this email (FR-001).
        $cleanup = $this->db->prepare('DELETE FROM login_attempts WHERE email = ?');
        $cleanup->bind_param('s', $email);
        $cleanup->execute();
        $cleanup->close();

        $user = new UserSession(
            userId: (int) $row['id'],
            companyId: (int) $row['company_id'],
            companyName: (string) $row['display_name'],
            companyLegalName: (string) $row['legal_name'],
            email: (string) $row['email'],
            fullName: (string) ($row['full_name'] ?? ''),
            roleId: (int) $row['role_id'],
            roleSlug: (string) $row['role_slug'],
        );

        $this->establishSession($user);
        $this->audit($user->userId, $user->companyId, 'auth.login', 'success', 'success', null);

        return $user;
    }

    /**
     * FR-002/SEC-002: Establish a role/tenant-scoped session with ID rotation.
     */
    private function establishSession(UserSession $user): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // SEC-002: set cookie params explicitly (do not rely on php.ini).
            session_set_cookie_params([
                'secure'   => true,       // HTTPS-only
                'httponly' => true,       // Not accessible via JS
                'samesite' => 'Lax',      // CSRF mitigation
            ]);
            session_start();
        }

        // SEC-002: rotate session ID after privilege change.
        session_regenerate_id(true);

        $_SESSION['routlaw_user_id'] = $user->userId;
        $_SESSION['routlaw_company'] = $user->companyName;   // tenant scope label (SEC-010)
        $_SESSION['routlaw_company_id'] = $user->companyId;  // tenant scope id (SEC-010)
        $_SESSION['routlaw_role_slug'] = $user->roleSlug;
        $_SESSION['routlaw_role_id'] = $user->roleId;
        $_SESSION['routlaw_email'] = $user->email;
        $_SESSION['routlaw_full_name'] = $user->fullName;
    }

    /**
     * SEC-002: Logout clears the session.
     */
    public function logout(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            // SEC-002: set cookie params explicitly (do not rely on php.ini).
            session_set_cookie_params([
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 3600,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
    }

    /**
     * Rate-limit check: too many failures within the lockout window (FR-001).
     * Uses the persistent login_attempts table so failures accumulate
     * across HTTP requests (mokimi lesson: per-process state is bypassed
     * by new requests).
     */
    private function isRateLimited(string $email): bool
    {
        $cutoff = time() - self::LOCKOUT_SECONDS;
        $cutoffDt = date('Y-m-d H:i:s', $cutoff);

        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE email = ? AND created_at > ?'
        );
        $stmt->bind_param('ss', $email, $cutoffDt);
        $stmt->execute();
        $result = $stmt->get_result();
        $count = (int) $result->fetch_column();
        $stmt->close();

        // Also clear expired attempts (keep the table bounded).
        $cleanup = $this->db->prepare('DELETE FROM login_attempts WHERE email = ? AND created_at <= ?');
        $cleanup->bind_param('ss', $email, $cutoffDt);
        $cleanup->execute();
        $cleanup->close();

        return $count >= self::MAX_LOGIN_ATTEMPTS;
    }

    /**
     * Record a failed login attempt in the persistent store (FR-001 rate-limiting).
     */
    private function recordFailure(string $email): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO login_attempts (email) VALUES (?)'
        );
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $stmt->close();
    }

    /**
     * Look up a user by email, including company and role info (tenant-scoped).
     */
    private function findUserByEmail(string $email): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT u.id, u.company_id, u.email, u.password_hash, u.full_name, u.role_id, '
            . 'c.display_name, c.legal_name, r.slug AS role_slug '
            . 'FROM users u '
            . 'JOIN companies c ON u.company_id = c.id '
            . 'JOIN roles r ON u.role_id = r.id '
            . 'WHERE u.email = ?'
        );
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    /**
     * Write an audit event (FR-029). Actor may be null for anonymous events.
     */
    private function audit(?int $userId, ?int $companyId, string $eventType, string $action, string $result, ?array $detail): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO audit_events (company_id, actor_type, actor_id, event_type, action, target_type, target_id, result, detail) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $actorType = $userId !== null ? 'user' : 'anonymous';
        $actorId = $userId ?? 0;
        $detailJson = $detail !== null ? json_encode($detail, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
        $companyIdVar = $companyId ?? 0;
        $targetType = '';
        $targetId = '';
        $stmt->bind_param(
            'issssssss',
            $companyIdVar,
            $actorType,
            $actorId,
            $eventType,
            $action,
            $targetType,
            $targetId,
            $result,
            $detailJson
        );
        $stmt->execute();
        $stmt->close();
    }
}
