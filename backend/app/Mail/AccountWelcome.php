<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Sent after registration. A mail failure does not roll the account back. */
final class AccountWelcome extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $name) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your account is ready');
    }

    public function content(): Content
    {
        $name = e($this->name);

        return new Content(htmlString: '<p>Welcome, '.$name.'.</p><p>Search a product to compare prices from other stores. Checkout happens on the store you choose.</p>');
    }
}
