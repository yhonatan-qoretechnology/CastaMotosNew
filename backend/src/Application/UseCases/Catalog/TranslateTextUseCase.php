<?php

declare(strict_types=1);

namespace App\Application\UseCases\Catalog;

use App\Infrastructure\Config\Config;

/**
 * Traducción automática ES → EN para los campos "_en" del admin (nombre,
 * descripción de productos/servicios) — MyMemory (api.mymemory.translated.net),
 * un servicio de traducción gratuito y sin API key (a diferencia del
 * proveedor de IA del asistente, que si necesita una cuenta paga). Sin
 * cuenta, sin configuración: funciona apenas se despliega el sitio.
 *
 * Límite de ~500 caracteres por pedido del lado de MyMemory: los textos
 * más largos (ej. descripciones) se parten en oraciones y se traducen por
 * partes, respetando ese límite, y se vuelven a unir.
 */
final class TranslateTextUseCase
{
    private const ENDPOINT = 'https://api.mymemory.translated.net/get';
    private const MAX_CHUNK_LENGTH = 480;
    private const TIMEOUT_SECONDS = 15;

    public function handle(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $chunks = $this->splitIntoChunks($text);
        $translated = array_map(fn (string $chunk) => $this->translateChunk($chunk), $chunks);

        return trim(implode(' ', $translated));
    }

    /** @return string[] */
    private function splitIntoChunks(string $text): array
    {
        if (mb_strlen($text) <= self::MAX_CHUNK_LENGTH) {
            return [$text];
        }

        // Se corta por oraciones (no a mitad de palabra) para no perder
        // sentido al traducir cada pedazo por separado.
        $sentences = preg_split('/(?<=[.!?])\s+/u', $text) ?: [$text];

        $chunks = [];
        $current = '';
        foreach ($sentences as $sentence) {
            $candidate = $current === '' ? $sentence : $current . ' ' . $sentence;
            if (mb_strlen($candidate) > self::MAX_CHUNK_LENGTH && $current !== '') {
                $chunks[] = $current;
                $current = $sentence;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function translateChunk(string $chunk): string
    {
        // "de" (email) es opcional para MyMemory, pero sube el límite diario
        // gratuito de 5.000 a 50.000 palabras — se reutiliza el correo del
        // sitio (MAIL_FROM_ADDRESS) en vez de pedir uno nuevo solo para esto.
        $params = [
            'q' => $chunk,
            'langpair' => 'es|en',
            'de' => (string) Config::get('app.mail.from_address', ''),
        ];

        $ch = curl_init(self::ENDPOINT . '?' . http_build_query($params));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT_SECONDS,
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false || $status !== 200) {
            throw new \RuntimeException('No fue posible traducir el texto — el servicio de traducción no respondió.');
        }

        $decoded = json_decode((string) $body, true);
        $translated = $decoded['responseData']['translatedText'] ?? null;

        if (!is_string($translated) || $translated === '') {
            throw new \RuntimeException('El servicio de traducción no devolvió un resultado válido.');
        }

        return $translated;
    }
}
