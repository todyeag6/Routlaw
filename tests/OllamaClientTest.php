<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Routlaw\Agent\Llm\Client as LlmClient;
use Routlaw\Agent\Llm\OllamaClient;

/**
 * T19-1 OllamaClient — keyless local LLM, reasoning-only boundary (Decision #2/#11).
 */
final class OllamaClientTest extends TestCase
{
    private bool $live = false;

    public function setUp(): void
    {
        // Gated live probe: only when explicitly requested, so phpunit stays GPU-free.
        $this->live = (getenv('ROUTLAW_LLM_INTEGRATION') === '1');
        if (!$this->live && !$this->ollamaAvailable()) {
            // skip the live path gracefully without marking the unit tests skipped.
        }
    }

    private function ollamaAvailable(): bool
    {
        $ch = curl_init('http://127.0.0.1:11434/api/tags');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code === 200;
    }

    public function test_implements_reasoning_only_interface(): void
    {
        $c = new OllamaClient();
        $this->assertInstanceOf(LlmClient::class, $c);
        $this->assertSame(LlmClient::DEFAULT_LOCAL_MODEL, 'qwen3.5:4b');
    }

    public function test_default_endpoint_is_local_keyless(): void
    {
        $c = new OllamaClient();
        $r = new \ReflectionProperty($c, 'baseUrl');
        $r->setAccessible(true);
        $this->assertSame('http://127.0.0.1:11434/v1', $r->getValue($c));
    }

    public function test_custom_base_url_and_model(): void
    {
        $c = new OllamaClient('http://127.0.0.1:11434/v1', 'gemma4:12b', 'ollama');
        $r1 = new \ReflectionProperty($c, 'baseUrl'); $r1->setAccessible(true);
        $r2 = new \ReflectionProperty($c, 'model');  $r2->setAccessible(true);
        $r3 = new \ReflectionProperty($c, 'apiKey');  $r3->setAccessible(true);
        $this->assertSame('http://127.0.0.1:11434/v1', $r1->getValue($c));
        $this->assertSame('gemma4:12b', $r2->getValue($c));
        $this->assertSame('ollama', $r3->getValue($c));
    }

    public function test_chat_with_bad_endpoint_throws(): void
    {
        $c = new OllamaClient('http://127.0.0.1:1', 'qwen3.5:4b');
        $this->expectException(\RuntimeException::class);
        $c->chat([['role' => 'user', 'content' => 'hi']]);
    }

    /** Gated live test — only runs with ROUTLAW_LLM_INTEGRATION=1. */
    public function test_live_chat_with_tool_call(): void
    {
        if (!$this->live || !$this->ollamaAvailable()) {
            $this->markTestSkipped('ROUTLAW_LLM_INTEGRATION not set or Ollama unavailable');
        }
        $c = new OllamaClient();
        $resp = $c->chat([
            ['role' => 'user', 'content' => 'Name a color. Use the tool_color tool to report it.'],
        ], [
            'num_ctx' => 4096,
            'tools'   => [[
                'type'    => 'function',
                'function' => ['name' => 'tool_color', 'description' => 'report a color', 'parameters' => ['type' => 'object', 'properties' => ['color' => ['type' => 'string']], 'required' => ['color']]],
            ]],
        ]);
        $this->assertIsArray($resp, 'Live Ollama chat must return a message array');
        $this->assertNotEmpty($resp['role'] ?? null);
    }
}
