<?php

namespace Tests\Unit;

use App\Support\MailHeader;
use PHPUnit\Framework\TestCase;

class MailHeaderTest extends TestCase
{
    public function test_encoded_subjects_are_decoded_without_changing_plain_text(): void
    {
        $this->assertSame('RE: Régularisation des prises', MailHeader::decode('=?iso-8859-1?Q?RE:_R=E9gularisation_des_prises?='));
        $this->assertSame('Chifaa — Ventes', MailHeader::decode('Chifaa =?utf-8?Q?=E2=80=94?= Ventes'));
        $this->assertSame('Réunion — vendredi', MailHeader::decode('Réunion — vendredi'));
        $this->assertSame('Équipe', MailHeader::decode('=?UTF-8?B?w4lxdWlwZQ==?='));
    }
}
