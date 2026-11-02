<?php

namespace App\Service;

use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

class ReservationPdf
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private string $projectDir,
        #[Autowire('%kernel.cache_dir%')] private string $cacheDir,
        #[Autowire('%cinema.address%')] private string $cinemaAddress,
        #[Autowire('%cinema.phone%')] private string $cinemaPhone,
        #[Autowire('%cinema.email%')] private string $cinemaEmail,
    ) {
    }

    public function generate(array $booking): string
    {
        $pdfDirectory = $this->cacheDir . '/pdf';
        if (!is_dir($pdfDirectory) && !mkdir($pdfDirectory, 0700, true) && !is_dir($pdfDirectory)) {
            throw new \RuntimeException('Le dossier temporaire des PDF est inaccessible.');
        }
        $options = new Options([
            'defaultFont' => 'DejaVu Sans',
            'isRemoteEnabled' => false,
            'isPhpEnabled' => false,
            'isJavascriptEnabled' => false,
            'tempDir' => $pdfDirectory,
            'fontCache' => $pdfDirectory,
            'chroot' => $this->projectDir . '/templates/pdf',
        ]);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($this->html($booking), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $pdf->getCanvas()->get_cpdf()->addInfo('Title', 'Confirmation de réservation ' . $booking['reference']);
        $pdf->getCanvas()->get_cpdf()->addInfo('Author', 'Le Grand Cinéma');

        return $pdf->output();
    }

    private function html(array $booking): string
    {
        $escape = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $date = (new \DateTimeImmutable($booking['startsAt']))->setTimezone(new \DateTimeZone('Europe/Paris'));
        $cancellationDeadline = $date->modify('-2 hours');
        $total = number_format($booking['totalAmountCents'] / 100, 2, ',', ' ');
        $unitPrice = number_format($booking['unitPriceCents'] / 100, 2, ',', ' ');
        $address = $this->cinemaAddress;
        $phone = $this->cinemaPhone;
        $email = $this->cinemaEmail;

        ob_start();
        try {
            require $this->projectDir . '/templates/pdf/reservation.php';

            return ob_get_contents();
        } finally {
            ob_end_clean();
        }
    }
}
