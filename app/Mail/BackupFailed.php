<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Avviso agli amministratori (BACKUP_ADMIN_EMAILS) quando il backup notturno fallisce: arriva anche se UnicoBPM non risponde.
 * Contiene solo nomi, codici di uscita e l'inizio dei messaggi d'errore: mai credenziali o contenuti dei dump.
 */
class BackupFailed extends Mailable
{
    /**
     * @param  array<string, mixed>  $report  Il campo `data` dell'evento backup.esito.
     */
    public function __construct(public array $report, public bool $bpmInformed) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Backup notturno fallito su '.($this->report['server'] ?? 'server'),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'mail.backup-failed',
            with: ['body' => implode("\n", $this->lines())],
        );
    }

    /**
     * Righe del testo, con una riga vuota tra le sezioni.
     *
     * @return list<string>
     */
    public function lines(): array
    {
        $report = $this->report;
        $lines = ['Il backup notturno del server '.($report['server'] ?? '-').' non è andato a buon fine ('.now()->format('d/m/Y H:i').', durata '.($report['seconds'] ?? 0).' s).', '', 'Fasi:'];

        foreach ((array) ($report['exit_codes'] ?? []) as $phase => $code) {
            $lines[] = '- '.$phase.': '.($code === 0 ? 'ok' : 'FALLITA (codice '.$code.')');
        }

        if (! empty($report['destinations'])) {
            $lines[] = '';
            $lines[] = 'Copia dei dump:';

            foreach ($report['destinations'] as $destination => $ok) {
                $lines[] = '- '.strtoupper((string) $destination).': '.($ok ? 'ok' : 'FALLITA');
            }
        }

        $lines[] = '';
        $lines[] = 'Database: '.($report['counts']['saved'] ?? 0).' salvati, '.($report['counts']['unchanged'] ?? 0).' invariati, '.($report['counts']['failed'] ?? 0).' falliti.';

        foreach ((array) ($report['databases'] ?? []) as $database) {
            if (! ($database['ok'] ?? false)) {
                $lines[] = '- '.$database['name'].': '.($database['error'] ?? 'errore');
            } elseif (isset($database['warning'])) {
                $lines[] = '- '.$database['name'].' (avviso): '.$database['warning'];
            }
        }

        $lines[] = '';
        $lines[] = $this->bpmInformed
            ? "L'esito è stato comunicato anche a UnicoBPM (Eventi ricevuti)."
            : "ATTENZIONE: UnicoBPM non ha ricevuto l'esito, questa email è l'unico avviso.";
        $lines[] = '';
        $lines[] = 'Dettagli nel pannello di backups3 e nel log storage/logs/laravel.log del server.';

        return $lines;
    }
}
