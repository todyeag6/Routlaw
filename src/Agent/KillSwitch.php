<?php
declare(strict_types=1);

namespace Routlaw\Agent;

/**
 * KillSwitch — T17 global/tenant halt (HAR-08, LLM10, SEC-017).
 *
 * When active, NO tool executes for ANY run in scope. Stored in system_flags
 * (key 'agent_kill_switch'). Operator-controlled only: there is NO method that accepts
 * model output to enable it. A null company_id = platform-wide; a specific company_id
 * scopes the halt to that tenant.
 */
final class KillSwitch
{
    private const KEY = 'agent_kill_switch';

    public function __construct(private \mysqli $db)
    {
    }

    public function activate(?int $companyId = null): void
    {
        $this->setFlag($companyId, true);
    }

    public function deactivate(?int $companyId = null): void
    {
        $this->setFlag($companyId, false);
    }

    public function isActive(?int $companyId = null): bool
    {
        // Active if a platform-wide (NULL company) OR the tenant-specific flag is on.
        $stmt = $this->db->prepare(
            'SELECT 1 FROM system_flags WHERE `key` = ? AND value_json = ? AND (company_id IS NULL OR company_id = ?) LIMIT 1'
        );
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare kill-switch check: ' . $this->db->error);
        }
        $key = self::KEY;
        $on = json_encode(['on' => true]);
        $on = $on === false ? '' : $on;
        $cid = $companyId ?? 0;
        $types = 'ssi';
        $params = [$key, $on, $cid];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $res = $stmt->get_result();
        $found = $res !== false && $res->fetch_assoc() !== null;
        $stmt->close();
        return $found;
    }

    private function setFlag(?int $companyId, bool $on): void
    {
        $value = json_encode(['on' => $on]);
        $value = $value === false ? '' : $value;
        $key = self::KEY;
        if ($companyId === null) {
            // Platform-wide: company_id is NULL.
            $stmt = $this->db->prepare(
                'INSERT INTO system_flags (`key`, company_id, value_json) VALUES (?, NULL, ?) '
                . 'ON DUPLICATE KEY UPDATE value_json = VALUES(value_json)'
            );
            if ($stmt === false) {
                throw new \RuntimeException('Failed to prepare kill-switch set: ' . $this->db->error);
            }
            $types = 'ss';
            /** @var list<mixed> $params */
            $params = [$key, $value];
            $this->assertBindArity($stmt, $types, $params);
            $bind = [$types];
            foreach ($params as $k => $v) {
                $bind[] = &$params[$k];
            }
            $stmt->bind_param(...$bind);
        } else {
            $stmt = $this->db->prepare(
                'INSERT INTO system_flags (`key`, company_id, value_json) VALUES (?, ?, ?) '
                . 'ON DUPLICATE KEY UPDATE value_json = VALUES(value_json)'
            );
            if ($stmt === false) {
                throw new \RuntimeException('Failed to prepare kill-switch set: ' . $this->db->error);
            }
            $types = 'sis';
            /** @var list<mixed> $params */
            $params = [$key, $companyId, $value];
            $this->assertBindArity($stmt, $types, $params);
            $bind = [$types];
            foreach ($params as $k => $v) {
                $bind[] = &$params[$k];
            }
            $stmt->bind_param(...$bind);
        }
        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to set kill switch: ' . $this->db->error);
        }
        $stmt->close();
    }

    /**
     * @param array<int, mixed> $params
     */
    private function assertBindArity(\mysqli_stmt $stmt, string $types, array $params): void
    {
        if (strlen($types) !== count($params)) {
            $stmt->close();
            throw new \LogicException(sprintf('bind_param arity mismatch: %d vs %d', strlen($types), count($params)));
        }
        $expected = ['i' => 'integer', 'd' => 'double', 's' => 'string', 'b' => 'string'];
        foreach ($params as $i => $p) {
            if ($p === null) {
                continue;
            }
            $want = $expected[$types[$i]] ?? null;
            if ($want !== null && gettype($p) !== $want) {
                $stmt->close();
                throw new \LogicException(sprintf('bind_param type mismatch at %d: "%s" expects %s, got %s', $i, $types[$i], $want, gettype($p)));
            }
        }
    }
}
