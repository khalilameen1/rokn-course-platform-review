<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AiInputAttachment;
use App\Services\AiInputAttachmentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PhpOffice\PhpWord\IOFactory as WordIOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;
use UnexpectedValueException;
use ZipArchive;

/**
 * Word attachments are read through PhpWord (fixtures written by PhpWord);
 * PowerPoint attachments are read in ECMA-376 slide order (fixtures laid out
 * as PowerPoint writes the package).
 */
final class AiInputAttachmentOfficeTextTest extends TestCase
{
    private const WORD = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private const POWERPOINT = 'application/vnd.openxmlformats-officedocument.presentationml.presentation';

    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('office-text');
    }

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    public function test_word_paragraphs_line_breaks_and_tabs_stay_apart(): void
    {
        $path = $this->wordDocument();

        $text = $this->providerText($path, 'notes.docx', self::WORD);

        self::assertStringContainsString("First\nSecond\tThird", $text);
        self::assertStringNotContainsString('FirstSecond', $text);
        self::assertStringNotContainsString('SecondThird', $text);
        self::assertStringContainsString("Third\nFourth & <fifth> خامس", $text);
        self::assertStringContainsString("Bullet one\nCell A\nCell B\nHeading six", $text);
        self::assertSame($text, $this->providerText($path, 'notes.docx', self::WORD, preserveFullText: true));
        self::assertSame(
            mb_strlen("FILE notes.docx\n".$text),
            app(AiInputAttachmentService::class)->reviewInputCharacterCount($this->uploaded($path, 'notes.docx'))
        );
    }

    public function test_slides_come_out_in_presentation_order_not_archive_order(): void
    {
        // Archive order is slide1 (alpha), slide2 (beta), slide3 (gamma);
        // the presentation shows gamma, alpha, beta.
        $path = $this->presentation(['Slide alpha', 'Slide beta', 'Slide gamma'], [3, 1, 2]);

        $text = $this->providerText($path, 'deck.pptx', self::POWERPOINT);

        self::assertSame("Slide gamma\nSlide alpha\nSlide beta", $text);
        self::assertSame($text, $this->providerText($path, 'deck.pptx', self::POWERPOINT, preserveFullText: true));
    }

    public function test_grouped_shapes_and_slides_without_relationships_read_in_order(): void
    {
        // Text inside a group (p:grpSp) and a slide whose own .rels part is
        // missing are both ordinary for the slide reader: it walks every a:p
        // of each slide named by p:sldIdLst.
        $grouped = $this->presentation(['Slide alpha', 'Slide beta', 'Slide gamma'], [3, 1, 2]);
        $this->rewritePart($grouped, 'ppt/slides/slide2.xml', static function (string $xml): string {
            // PowerPoint's markup for a grouped text box.
            return (string) preg_replace(
                '#(<p:sp>.*</p:sp>)#s',
                '<p:grpSp><p:nvGrpSpPr><p:cNvPr id="9" name="Group 1"/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>$1</p:grpSp>',
                $xml
            );
        });
        self::assertSame(
            "Slide gamma\nSlide alpha\nSlide beta",
            $this->providerText($grouped, 'grouped.pptx', self::POWERPOINT)
        );

        $withoutRelationships = $this->presentation(['Slide alpha', 'Slide beta', 'Slide gamma'], [3, 1, 2]);
        $this->rewritePart($withoutRelationships, 'ppt/slides/_rels/slide3.xml.rels', null);
        self::assertSame(
            "Slide gamma\nSlide alpha\nSlide beta",
            $this->providerText($withoutRelationships, 'bare.pptx', self::POWERPOINT)
        );
    }

    public function test_full_text_review_refuses_more_than_one_hundred_slides(): void
    {
        $path = $this->presentation(array_map(static fn (int $n): string => "Slide {$n}", range(1, 101)), range(1, 101));
        $service = app(AiInputAttachmentService::class);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('The whole presentation exceeds the review extraction limit.');
        $service->reviewInputCharacterCount($this->uploaded($path, 'long.pptx'));
    }

    public function test_an_xml_part_that_expands_past_the_limit_is_refused(): void
    {
        // A few kilobytes compressed, over two megabytes expanded.
        $path = $this->wordDocument();
        $this->rewritePart($path, 'word/document.xml', static function (string $xml): string {
            return (string) preg_replace(
                '#<w:body>.*</w:body>#s',
                '<w:body><w:p><w:r><w:t>'.str_repeat('a', 2 * 1024 * 1024 + 1).'</w:t></w:r></w:p></w:body>',
                $xml
            );
        });
        $service = app(AiInputAttachmentService::class);

        try {
            $service->reviewInputCharacterCount($this->uploaded($path, 'bomb.docx'));
            self::fail('Full-text review accepted an oversized document part.');
        } catch (UnexpectedValueException $exception) {
            self::assertSame('The whole document cannot be extracted within the review limit.', $exception->getMessage());
        }

        // Chat attachments skip what does not fit, so nothing is left to send.
        $this->expectException(UnexpectedValueException::class);
        $this->providerText($path, 'bomb.docx', self::WORD);
    }

    public function test_a_package_with_too_many_entries_is_refused(): void
    {
        $path = $this->wordDocument();
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path));
        for ($index = 0; $index < 10001; $index++) {
            $zip->addFromString("word/media/{$index}.txt", 'x');
        }
        $zip->close();

        $this->expectException(UnexpectedValueException::class);
        $this->providerText($path, 'flood.docx', self::WORD);
    }

    private function providerText(string $file, string $name, string $mime, bool $preserveFullText = false): string
    {
        $bytes = (string) file_get_contents($file);
        $path = 'inputs/'.Str::uuid().'/'.$name;
        Storage::disk('office-text')->put($path, $bytes);
        $attachment = (new AiInputAttachment)->forceFill([
            'storage_disk' => 'office-text',
            'storage_path' => $path,
            'original_file_name' => $name,
            'mime_type' => $mime,
            'size_bytes' => strlen($bytes),
        ]);

        $parts = app(AiInputAttachmentService::class)->providerParts(collect([$attachment]), $preserveFullText);

        self::assertCount(1, $parts);
        self::assertSame('text', $parts[0]['type']);
        self::assertStringStartsWith("FILE {$name}\n", $parts[0]['text']);

        return Str::after($parts[0]['text'], "FILE {$name}\n");
    }

    private function uploaded(string $file, string $name): UploadedFile
    {
        return new UploadedFile($file, $name, 'application/zip', null, true);
    }

    /** Path of a Word document with a run-level line break, a tab, paragraphs, a list, a table and a heading. */
    private function wordDocument(): string
    {
        $document = new PhpWord;
        $section = $document->addSection();
        $paragraph = $section->addTextRun();
        $paragraph->addText('First');
        $paragraph->addTextBreak();
        $paragraph->addText('Second');
        $paragraph->addText("\t");
        $paragraph->addText('Third');
        $section->addText('Fourth &amp; &lt;fifth&gt; خامس');
        $section->addListItem('Bullet one');
        $table = $section->addTable();
        $table->addRow();
        $table->addCell(2000)->addText('Cell A');
        $table->addCell(2000)->addText('Cell B');
        $section->addTitle('Heading six', 1);
        $path = $this->temporary();
        WordIOFactory::createWriter($document, 'Word2007')->save($path);
        // PhpWord writes a bare w:br and a literal tab; Word nests both in a
        // run (ECMA-376 17.3.3.1 br, 17.3.3.30 tab), which the reader expects.
        $this->rewritePart($path, 'word/document.xml', static fn (string $xml): string => str_replace(
            ['<w:br/>', "<w:t xml:space=\"preserve\">\t</w:t>"],
            ['<w:r><w:br/></w:r>', '<w:tab/>'],
            $xml
        ));

        return $path;
    }

    /**
     * Path of a presentation laid out as PowerPoint writes it, slides in
     * archive order slide1..N; the presentation order is given as one-based
     * slide numbers for p:sldIdLst.
     *
     * @param  list<string>  $titles
     * @param  list<int>  $order
     */
    private function presentation(array $titles, array $order): string
    {
        $path = $this->temporary();
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path, ZipArchive::OVERWRITE));
        $overrides = '';
        $relationships = '';
        $slideIds = '';
        foreach ($titles as $index => $title) {
            $number = $index + 1;
            $overrides .= '<Override PartName="/ppt/slides/slide'.$number.'.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
            $relationships .= '<Relationship Id="rId'.(10 + $number).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slide" Target="slides/slide'.$number.'.xml"/>';
            $zip->addFromString(
                "ppt/slides/slide{$number}.xml",
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
                .'<p:cSld><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr/>'
                .'<p:sp><p:nvSpPr><p:cNvPr id="2" name="Title 1"/><p:cNvSpPr><a:spLocks noGrp="1"/></p:cNvSpPr><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr><p:spPr/>'
                .'<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:r><a:rPr lang="en-US" dirty="0"/><a:t>'.htmlspecialchars($title, ENT_XML1).'</a:t></a:r><a:endParaRPr lang="en-US" dirty="0"/></a:p></p:txBody></p:sp>'
                .'</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>'
            );
            $zip->addFromString(
                "ppt/slides/_rels/slide{$number}.xml.rels",
                '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'
                .'</Relationships>'
            );
        }
        foreach ($order as $position => $slideNumber) {
            $slideIds .= '<p:sldId id="'.(256 + $position).'" r:id="rId'.(10 + $slideNumber).'"/>';
        }
        $zip->addFromString(
            '[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            .$overrides.'</Types>'
        );
        $zip->addFromString(
            '_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="ppt/presentation.xml"/>'
            .'</Relationships>'
        );
        $zip->addFromString(
            'ppt/_rels/presentation.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/slideMaster" Target="slideMasters/slideMaster1.xml"/>'
            .$relationships.'</Relationships>'
        );
        $zip->addFromString(
            'ppt/presentation.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'
            .'<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst>'
            .'<p:sldIdLst>'.$slideIds.'</p:sldIdLst>'
            .'<p:sldSz cx="12192000" cy="6858000"/><p:notesSz cx="6858000" cy="9144000"/>'
            .'</p:presentation>'
        );
        $zip->close();

        return $path;
    }

    /** @param  (callable(string): string)|null  $rewrite  null removes the part */
    private function rewritePart(string $path, string $part, ?callable $rewrite): void
    {
        $zip = new ZipArchive;
        self::assertTrue($zip->open($path));
        $xml = $zip->getFromName($part);
        self::assertIsString($xml);
        if ($rewrite === null) {
            self::assertTrue($zip->deleteName($part));
        } else {
            self::assertTrue($zip->addFromString($part, $rewrite($xml)));
        }
        $zip->close();
    }

    private function temporary(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rokn-office-');
        self::assertIsString($path);
        $this->temporaryFiles[] = $path;

        return $path;
    }
}
