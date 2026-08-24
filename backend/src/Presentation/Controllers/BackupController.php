<?php

declare(strict_types=1);

namespace App\Presentation\Controllers;

use App\Application\UseCases\Backup\GenerateDatabaseBackupUseCase;
use App\Exceptions\ValidationException;
use App\Infrastructure\Config\Config;
use App\Infrastructure\Database\Connection;
use App\Infrastructure\Http\Request;
use App\Infrastructure\Http\Response;
use App\Infrastructure\Mail\EmailTemplates;
use App\Infrastructure\Mail\Mailer;

/**
 * Backup de la base de datos por correo (/admin → Configuración, permiso
 * manage-settings) — genera un dump SQL completo (sin depender de que
 * "mysqldump" esté disponible en el hosting, ver GenerateDatabaseBackupUseCase)
 * y lo manda como adjunto comprimido al correo configurado en BACKUP_EMAIL
 * (o MAIL_FROM_ADDRESS si no se cargó uno específico, ver config/app.php).
 */
final class BackupController
{
    public function send(Request $request): void
    {
        $to = (string) Config::get('app.backup.email', '');
        if ($to === '') {
            throw new ValidationException('No fue posible generar el backup.', [
                'email' => ['No hay un correo configurado para recibir el backup (BACKUP_EMAIL / MAIL_FROM_ADDRESS en .env).'],
            ]);
        }

        $result = (new GenerateDatabaseBackupUseCase(Connection::get()))->handle();

        $gzipped = gzencode($result['sql'], 9);
        if ($gzipped === false) {
            throw new ValidationException('No fue posible generar el backup.', [
                'backup' => ['Ocurrió un error al comprimir el archivo.'],
            ]);
        }

        $filename = sprintf('backup_%s_%s.sql.gz', $result['database_name'], date('Y-m-d_His'));
        $adminUrl = rtrim((string) Config::get('app.url'), '/') . '/admin';

        $content = EmailTemplates::backupEmail(
            $result['database_name'],
            $result['table_count'],
            $this->formatSize(strlen($gzipped)),
            $adminUrl
        );

        $sent = Mailer::sendWithAttachment(
            $to,
            $content['subject'],
            $content['html'],
            'database_backup',
            $filename,
            $gzipped,
            'application/gzip'
        );

        if (!$sent) {
            throw new ValidationException('No fue posible enviar el backup.', [
                'email' => ['El correo no pudo enviarse — revisá la configuración de SMTP (backend/.env) e intentá de nuevo.'],
            ]);
        }

        Response::success(
            ['email' => $to, 'table_count' => $result['table_count']],
            "Backup generado y enviado a {$to}."
        );
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }
        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / (1024 * 1024), 1) . ' MB';
    }
}
