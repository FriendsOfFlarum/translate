<?php

/*
 * This file is part of fof/translate.
 *
 * Copyright (c) FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\Translate\Tests\unit\Detection;

use Flarum\Settings\UninstalledSettingsRepository;
use FoF\Translate\Detection\ContentLanguage;
use FoF\Translate\Detection\EldLanguageDetector;
use FoF\Translate\Detection\LanguageDetector;
use FoF\Translate\Detection\PlainText;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContentLanguageTest extends TestCase
{
    #[Test]
    public function detects_prose_in_an_otherwise_ambiguous_table_heavy_post(): void
    {
        $settings = new UninstalledSettingsRepository();
        $eld = new EldLanguageDetector($settings);
        $detector = new ContentLanguage($eld, $settings);

        $post = <<<'XML'
            <r>
              <p>Demo sites — daily reset</p>
              <p>Reset completed 2026-01-02 06:13 UTC. All data is wiped and passwords rotated on every reset.</p>
              <TABLE>
                <THEAD><TR><TH>site</TH><TH>flarum</TH><TH>ref</TH><TH>database</TH><TH>debug</TH><TH>mail</TH></TR></THEAD>
                <TBODY>
                  <TR><TD>demo</TD><TD><C>2.0.2</C></TD><TD><C>v2.0.2</C></TD><TD>mariadb</TD><TD>off</TD><TD>null</TD></TR>
                  <TR><TD>nightly</TD><TD><C>2.1.0-dev</C></TD><TD><C>8d03ae1</C></TD><TD>mariadb</TD><TD>off</TD><TD>null</TD></TR>
                  <TR><TD>qa</TD><TD><C>2.1.0-dev</C></TD><TD><C>8d03ae1</C></TD><TD>mariadb</TD><TD>on</TD><TD>mailtrap</TD></TR>
                  <TR><TD>nightlypg</TD><TD><C>2.1.0-dev</C></TD><TD><C>8d03ae1</C></TD><TD>pgsql</TD><TD>off</TD><TD>null</TD></TR>
                </TBODY>
              </TABLE>
              <H3>demo</H3><LIST><LI>admin — <C>example</C></LI><LI>moderator — <C>example</C></LI><LI>user — <C>example</C></LI></LIST>
              <H3>nightly</H3><LIST><LI>admin — <C>example</C></LI><LI>moderator — <C>example</C></LI><LI>user — <C>example</C></LI></LIST>
              <H3>qa</H3><LIST><LI>admin — <C>example</C></LI><LI>moderator — <C>example</C></LI><LI>user — <C>example</C></LI><LI>basic auth — <C>example</C></LI></LIST>
              <H3>nightlypg</H3><LIST><LI>admin — <C>example</C></LI><LI>moderator — <C>example</C></LI><LI>user — <C>example</C></LI></LIST>
              <p>Passwords are valid until the next reset.</p>
            </r>
            XML;

        $this->assertNull($eld->detect(PlainText::fromXml($post)));
        $this->assertSame('en', $detector->ofPostXml($post));
    }

    #[Test]
    public function retains_a_reliable_language_from_a_table_without_surrounding_prose(): void
    {
        $settings = new UninstalledSettingsRepository();
        $detector = new ContentLanguage(new EldLanguageDetector($settings), $settings);

        $post = '<r><TABLE><TR><TD>Bonjour à tous, je voudrais savoir comment installer cette extension sur mon forum. Merci pour votre aide !</TD></TR></TABLE></r>';

        $this->assertSame('fr', $detector->ofPostXml($post, 'en'));
    }

    #[Test]
    #[DataProvider('tableRetryCases')]
    public function retries_detection_only_when_the_prose_is_representative(string $post, array $answers, string $expected): void
    {
        $settings = new UninstalledSettingsRepository();
        $detector = $this->createMock(LanguageDetector::class);
        $detector->expects($this->exactly(count($answers)))
            ->method('detect')
            ->willReturnCallback(function (string $text) use (&$answers): ?string {
                $this->assertArrayHasKey($text, $answers);

                $language = $answers[$text];
                unset($answers[$text]);

                return $language;
            });

        $this->assertSame($expected, (new ContentLanguage($detector, $settings))->ofPostXml($post));
    }

    public static function tableRetryCases(): array
    {
        $prose = 'The forum is ready for testing today.';
        $noise = 'alpha beta gamma delta';
        $table = '<TABLE><TD>'.$noise.'</TD></TABLE>';
        $equal = str_repeat('x', strlen($prose));
        $longProse = str_repeat('The forum is ready for testing. ', 40).'Welcome.';
        $longTable = str_repeat('1234567890 ', 100).'Bonjour';

        return [
            'reliable full post' => ['<r>'.$prose.$table.'</r>', [$prose.' '.$noise => 'en'], 'en'],
            'ambiguous without a table' => ['<r>'.$prose.'</r>', [$prose => null], ''],
            'substantial prose beside an ambiguous table' => ['<r>'.$prose.$table.'</r>', [$prose.' '.$noise => null, $prose => 'en', $noise => null], 'en'],
            'same language on both sides' => ['<r>'.$prose.$table.'</r>', [$prose.' '.$noise => null, $prose => 'en', $noise => 'en'], 'en'],
            'table with a different language' => ['<r>'.$prose.$table.'</r>', [$prose.' '.$noise => null, $prose => 'en', $noise => 'fr'], ''],
            'prose and table have equal lengths' => ['<r>'.$prose.'<TABLE><TD>'.$equal.'</TD></TABLE></r>', [$prose.' '.$equal => null, $prose => 'en', $equal => null], 'en'],
            'substantial prose below the minimum' => ['<r>Hi there<TABLE><TD>abc d</TD></TABLE></r>', ['Hi there abc d' => null], ''],
            'table outweighs the heading' => ['<r>See the table'.$table.'</r>', ['See the table '.$noise => null], ''],
            'nested tables' => ['<r>'.$prose.'<TABLE><TD>nested '.$table.'</TD></TABLE></r>', [$prose.' nested '.$noise => null], ''],
            'several tables' => ['<r>'.$prose.$table.$table.'</r>', [$prose.' '.$noise.' '.$noise => null], ''],
            'table extends beyond the detector sample' => ['<r>'.$longProse.'<TABLE><TD>'.$longTable.'</TD></TABLE></r>', [$longProse.' '.$longTable => null], ''],
        ];
    }
}
