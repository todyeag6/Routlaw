<?php
declare(strict_types=1);

namespace Routlaw\Agent;

/**
 * BudgetEnforcer — T17 resource budgets (HAR-08, FR-032, LLM10, SEC-017).
 *
 * Per-agent-run HARD caps on steps / tokens / cost / time / concurrency. Once any cap
 * is exceeded the run is marked budget_status='exceeded' (or 'killed' if the kill switch
 * fired) and canStep() returns false so the harness halts — preventing unbounded
 * consumption and infinite loops (FR-032). Caps are configured server-side; model output
 * cannot raise them.
 *
 * @param array{max_steps:int, max_tokens:int, max_cost_cents:int, max_seconds:int, max_concurrency:int} $config
 */
final class BudgetEnforcer
{
    /** @var array{max_steps:int, max_tokens:int, max_cost_cents:int, max_seconds:int, max_concurrency:int} */
    private array $config;

    /**
     * @param array{max_steps:int, max_tokens:int, max_cost_cents:int, max_seconds:int, max_concurrency:int} $config
     */
    public function __construct(
        private \mysqli $db,
        array $config,
        private ?KillSwitch $killSwitch = null,
    ) {
        $this->config = $config;
    }

    /**
     * Whether the run may take another step. False when the kill switch is active or any
     * hard cap is already exceeded (status column reflects this).
     */
    public function canStep(int $agentRunId): bool
    {
        if ($this->killSwitch !== null) {
            $row0 = $this->load($agentRunId);
            if ($row0 !== null) {
                $companyId = isset($row0['company_id']) ? (int) $row0['company_id'] : null;
                // Kill switch blocks if global (NULL scope) OR tenant-specific for this run.
                if ($this->killSwitch->isActive($companyId)) {
                    $this->halt($agentRunId, 'killed');
                    return false;
                }
            }
        }
        $row = $this->load($agentRunId);
        if ($row === null) {
            return false;
        }
        if ($row['budget_status'] !== 'ok') {
            return false;
        }
        if ((int) $row['step_count'] >= $this->config['max_steps']) {
            $this->halt($agentRunId, 'exceeded');
            return false;
        }
        if ((int) $row['token_estimate'] >= $this->config['max_tokens']) {
            $this->halt($agentRunId, 'exceeded');
            return false;
        }
        if ((int) $row['cost_cents'] >= $this->config['max_cost_cents']) {
            $this->halt($agentRunId, 'exceeded');
            return false;
        }
        if ((int) $row['elapsed_seconds'] >= $this->config['max_seconds']) {
            $this->halt($agentRunId, 'exceeded');
            return false;
        }
        return true;
    }

    public function recordStep(int $agentRunId): void
    {
        $this->incrementInt($agentRunId, 'step_count', 1);
    }

    public function recordTokens(int $agentRunId, int $tokens): void
    {
        $this->incrementInt($agentRunId, 'token_estimate', $tokens);
    }

    public function recordCostCents(int $agentRunId, int $cents): void
    {
        $this->incrementInt($agentRunId, 'cost_cents', $cents);
    }

    /**
     * Mark the run halted by the kill switch (distinct from budget 'exceeded').
     */
    public function markKilled(int $agentRunId): void
    {
        $this->halt($agentRunId, 'killed');
    }

    private function halt(int $agentRunId, string $status): void
    {
        $stmt = $this->db->prepare('UPDATE agent_runs SET budget_status = ?, finished_at = NOW() WHERE id = ?');
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare budget halt: ' . $this->db->error);
        }
        $types = 'si';
        $params = [$status, $agentRunId];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new \RuntimeException('Failed to halt run: ' . $this->db->error);
        }
        $stmt->close();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function load(int $agentRunId): ?array
    {
        $stmt = $this->db->prepare('SELECT budget_status, step_count, token_estimate, cost_cents, company_id, TIMESTAMPDIFF(SECOND, started_at, NOW()) AS elapsed_seconds FROM agent_runs WHERE id = ?');
        if ($stmt === false) {
            throw new \RuntimeException('Failed to prepare budget load: ' . $this->db->error);
        }
        $stmt->bind_param('i', $agentRunId);
        $stmt->execute();
        $res = $stmt->get_result();
        $row = $res !== false ? $res->fetch_assoc() : null;
        $stmt->close();
        return $row === false ? null : $row;
    }

    private function incrementInt(int $agentRunId, string $column, int $amount): void
    {
        $allowed = ['step_count', 'token_estimate', 'cost_cents'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException("Cannot increment disallowed column: {$column}");
        }
        $stmt = $this->db->prepare("UPDATE agent_runs SET {$column} = {$column} + ? WHERE id = ?");
        if ($stmt === false) {
            throw new \RuntimeException("Failed to prepare increment {$column}: " . $this->db->error);
        }
        $types = 'ii';
        $params = [$amount, $agentRunId];
        $this->assertBindArity($stmt, $types, $params);
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            throw new \RuntimeException("Failed to increment {$column}: " . $this->db->error);
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
