<?php

declare(strict_types=1);

namespace App\Application\UseCases\Catalog;

use App\Application\Ai\AiProviderFactory;

/**
 * Traducción automática ES → EN para los campos "_en" del admin (nombre,
 * descripción de productos/servicios) — reutiliza el mismo proveedor de IA
 * ya conectado para el asistente de preguntas (AI_PROVIDER/AI_API_KEY,
 * backend/.env), no agrega ninguna integración nueva. Si no hay proveedor
 * configurado, AiProviderFactory::make() lanza ValidationException y el
 * controller la deja pasar tal cual — el campo "_en" simplemente se sigue
 * llenando a mano, como hasta ahora.
 */
final class TranslateTextUseCase
{
    private const SYSTEM_PROMPT = 'You translate short e-commerce catalog text (product/service names and '
        . 'descriptions) from Spanish to natural, concise English for a motorcycle marketplace. '
        . 'Reply with ONLY the translated text — no quotes, no preamble, no explanation.';

    public function handle(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $reply = AiProviderFactory::make()->reply(self::SYSTEM_PROMPT, [
            ['role' => 'user', 'content' => $text],
        ]);

        return trim($reply);
    }
}
