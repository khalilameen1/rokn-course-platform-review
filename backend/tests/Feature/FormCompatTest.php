<?php

declare(strict_types=1);

namespace Tests\Feature;

use Collective\Html\FormFacade as Form;
use Collective\Html\FormBuilder;
use Tests\TestCase;

final class FormCompatTest extends TestCase
{
    public function test_current_admin_form_surface_preserves_model_binding(): void
    {
        $this->startSession();
        self::assertInstanceOf(FormBuilder::class, app('form'));

        $model = (object) [
            'title_ar' => 'عنوان الكورس',
            'status' => 'published',
            'featured' => 1,
        ];

        $opening = Form::model($model, [
            'method' => 'PATCH',
            'url' => '/admin/courses/42',
            'files' => true,
            'class' => 'course-form',
        ]);

        $openingHtml = $opening->toHtml();
        self::assertStringContainsString('method="POST"', $openingHtml);
        self::assertStringContainsString('enctype="multipart/form-data"', $openingHtml);
        self::assertStringContainsString('name="_method"', $openingHtml);
        self::assertStringContainsString('value="PATCH"', $openingHtml);
        self::assertStringContainsString('name="_token"', $openingHtml);
        self::assertNotSame('', $this->hiddenValue($openingHtml, '_token'));

        self::assertStringContainsString(
            'value="عنوان الكورس"',
            Form::text('title_ar', null, ['required'])->toHtml()
        );
        self::assertStringContainsString(
            '<option value="published" selected="selected">Published</option>',
            Form::select('status', ['draft' => 'Draft', 'published' => 'Published'])->toHtml()
        );
        self::assertStringContainsString(
            'checked="checked"',
            Form::checkbox('featured', 1)->toHtml()
        );
        self::assertStringContainsString(
            'type="url"',
            Form::url('support_url', 'https://rokn.app/support')->toHtml()
        );
        self::assertSame('</form>', Form::close()->toHtml());
    }

    public function test_state_changing_form_regenerates_a_blank_session_token(): void
    {
        $this->startSession();
        session()->put('_token', '');

        $openingHtml = Form::open([
            'method' => 'PATCH',
            'url' => '/admin/courses/42',
        ])->toHtml();

        self::assertSame('PATCH', $this->hiddenValue($openingHtml, '_method'));
        self::assertNotSame('', $this->hiddenValue($openingHtml, '_token'));
        self::assertSame(session()->token(), $this->hiddenValue($openingHtml, '_token'));
        Form::close();
    }

    public function test_form_builder_is_scoped_instead_of_leaking_mutable_model_state(): void
    {
        $first = app('form');

        app()->forgetScopedInstances();
        Form::clearResolvedInstance('form');

        self::assertNotSame($first, app('form'));
    }

    private function hiddenValue(string $html, string $name): string
    {
        $matched = preg_match(
            '/<input[^>]*name="'.preg_quote($name, '/').'"[^>]*value="([^"]*)"[^>]*>/i',
            $html,
            $matches
        );

        self::assertSame(1, $matched, "Missing hidden field {$name}");

        return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
