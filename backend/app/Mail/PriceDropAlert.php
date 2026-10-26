<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** The best in-stock total for a saved search is lower than the last one we stored. */
final class PriceDropAlert extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $query,
        public readonly string $currency,
        public readonly int $totalMinor,
        public readonly string $reason = 'drop',
        public readonly int $exponent = 2,
    ) {}

    public function envelope(): Envelope
    {
        $prefix = $this->reason === 'target' ? 'Target price' : 'Price drop';

        return new Envelope(subject: $prefix.': '.$this->query);
    }

    public function content(): Content
    {
        $exponent = max(0, $this->exponent);
        $amount = e($this->currency).' '.e(number_format($this->totalMinor / (10 ** $exponent), $exponent, '.', ''));
        $query = e($this->query);

        return new Content(htmlString: '<p>The best price we found for '.$query.' is now '.$amount.', including shipping when the store published it.</p><p>Open the app to compare the current offers. The purchase is completed on the store site.</p>');
    }
}
