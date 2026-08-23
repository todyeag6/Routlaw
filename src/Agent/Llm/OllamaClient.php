<?php
declare(strict_types=1);

namespace Routlaw\Agent\Llm;

/**
 * Local Ollama client via the OpenAI-compatible /v1/chat/completions endpoint.
 *
 * Keyless + self-hosted (Decision #2/#11, 2026-08-23). Reads model/base_url from
 * config; defaults to the operator's 6 GB-class GPU profile (qwen3.5:4b).
 *
 * Security: this client ONLY returns model reasoning. It never reads or writes
 * proposals/flags/memory. The policy boundary is structural, not prompt-influenced.
 */
final class OllamaClient implements Client
{
    private string $baseUrl;
    private string $model;

    public function __construct(
        ?string $baseUrl = null,
        ?string $model = null,
        private ?string $apiKey = null,
    ) {
        $this->baseUrl = $baseUrl ?? 'http://127.0.0.1:11434/v1';
        $this->model   = $model ?? self::DEFAULT_LOCAL_MODEL;
    }

    /**
     * @param list<array<string,mixed>> $messages
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    public function chat(array $messages, array $options = []): array
    {
        $numCtx = $options['num_ctx'] ?? 65536;
        $payload = [
            'model'    => $this->model,
            'messages' => $messages,
            'options'  => ['num_ctx' => $numCtx],
        ];
        if (isset($options['tools'])) {
            $payload['tools'] = $options['tools'];
        }
        $temp = $options['temperature'] ?? 0.2;
        if ($temp !== null) {
            $payload['options']['temperature'] = $temp;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new \RuntimeException('Failed to encode chat payload');
        }

        $headers = ['Content-Type: application/json'];
        if ($this->apiKey !== null) {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $raw = curl_exec($ch);
        if ($raw === false) {
            throw new \RuntimeException('Ollama curl error: ' . curl_error($ch));
        }
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 400) {
            throw new \RuntimeException("Ollama HTTP {$code}: {$raw}");
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Ollama returned non-JSON response');
        }

        // The /v1 endpoint mirrors OpenAI; map to the canonical message shape the
        // harness consumes (role + content + optional tool_calls).
        $msg = $decoded['choices'][0]['message'] ?? null;
        if (!is_array($msg)) {
            throw new \RuntimeException('Ollama response missing choices[0].message');
        }
        return $msg;
    }
}
