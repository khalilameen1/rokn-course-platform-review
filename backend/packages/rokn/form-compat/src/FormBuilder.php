<?php

namespace Rokn\FormCompat;

use DateTimeInterface;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;
use LogicException;

/**
 * Deliberately small Form facade implementation.
 *
 * Rokn's admin templates use only the methods in this class. Keeping this
 * surface local avoids depending on the abandoned laravelcollective/html
 * package while preserving the templates until they are incrementally moved
 * to native Blade components.
 */
class FormBuilder
{
    private mixed $model = null;

    public function __construct(private readonly UrlGenerator $url)
    {
    }

    public function open(array $options = []): HtmlString
    {
        $method = strtoupper((string) ($options['method'] ?? 'POST'));
        $htmlMethod = in_array($method, ['GET', 'POST'], true) ? $method : 'POST';
        $action = $this->resolveAction($options);

        $attributes = Arr::except($options, [
            'action', 'files', 'method', 'route', 'secure', 'url',
        ]);
        $attributes['method'] = $htmlMethod;
        $attributes['action'] = $action;
        $attributes['accept-charset'] ??= 'UTF-8';

        if (($options['files'] ?? false) === true) {
            $attributes['enctype'] = 'multipart/form-data';
        }

        $html = '<form' . $this->attributes($attributes) . '>';
        if ($method !== $htmlMethod) {
            $html .= $this->hidden('_method', $method);
        }
        if ($htmlMethod !== 'GET') {
            $html .= $this->hidden('_token', $this->csrfToken());
        }

        return new HtmlString($html);
    }

    public function model(mixed $model, array $options = []): HtmlString
    {
        $this->model = $model;

        return $this->open($options);
    }

    public function close(): HtmlString
    {
        $this->model = null;

        return new HtmlString('</form>');
    }

    public function text(string $name, mixed $value = null, array $options = []): HtmlString
    {
        return $this->input('text', $name, $this->value($name, $value), $options);
    }

    public function email(string $name, mixed $value = null, array $options = []): HtmlString
    {
        return $this->input('email', $name, $this->value($name, $value), $options);
    }

    public function password(string $name, array $options = []): HtmlString
    {
        return $this->input('password', $name, null, $options);
    }

    public function number(string $name, mixed $value = null, array $options = []): HtmlString
    {
        return $this->input('number', $name, $this->value($name, $value), $options);
    }

    public function hidden(string $name, mixed $value = null, array $options = []): HtmlString
    {
        return $this->input('hidden', $name, $value, $options);
    }

    public function date(string $name, mixed $value = null, array $options = []): HtmlString
    {
        $value = $this->value($name, $value);
        if ($value instanceof DateTimeInterface) {
            $value = $value->format('Y-m-d');
        }

        return $this->input('date', $name, $value, $options);
    }

    public function textarea(string $name, mixed $value = null, array $options = []): HtmlString
    {
        $options['name'] = $name;
        $options['id'] ??= $this->fieldId($name);

        return new HtmlString(
            '<textarea' . $this->attributes($options) . '>'
            . e((string) ($this->value($name, $value) ?? ''))
            . '</textarea>'
        );
    }

    public function select(
        string $name,
        iterable $list = [],
        mixed $selected = null,
        array $options = []
    ): HtmlString {
        $selected = $this->value($name, $selected);
        $selectedValues = is_array($selected)
            ? array_map('strval', $selected)
            : [(string) $selected];

        $options['name'] = $name;
        $options['id'] ??= $this->fieldId($name);
        $html = '<select' . $this->attributes($options) . '>';

        foreach ($list as $value => $label) {
            if (is_iterable($label)) {
                $html .= '<optgroup label="' . e((string) $value) . '">';
                foreach ($label as $nestedValue => $nestedLabel) {
                    $html .= $this->option($nestedValue, $nestedLabel, $selectedValues);
                }
                $html .= '</optgroup>';
                continue;
            }

            $html .= $this->option($value, $label, $selectedValues);
        }

        return new HtmlString($html . '</select>');
    }

    public function checkbox(
        string $name,
        mixed $value = 1,
        ?bool $checked = null,
        array $options = []
    ): HtmlString {
        return $this->checkable('checkbox', $name, $value, $checked, $options);
    }

    public function radio(
        string $name,
        mixed $value = null,
        ?bool $checked = null,
        array $options = []
    ): HtmlString {
        return $this->checkable('radio', $name, $value, $checked, $options);
    }

    private function checkable(
        string $type,
        string $name,
        mixed $value,
        ?bool $checked,
        array $options
    ): HtmlString {
        if ($checked === null) {
            $current = $this->value($name, null);
            $checked = is_array($current)
                ? in_array((string) $value, array_map('strval', $current), true)
                : (string) $current === (string) $value;
        }

        if ($checked) {
            $options['checked'] = true;
        }

        return $this->input($type, $name, $value, $options);
    }

    private function input(
        string $type,
        string $name,
        mixed $value,
        array $options
    ): HtmlString {
        $options['type'] = $type;
        $options['name'] = $name;
        $options['id'] ??= $this->fieldId($name);
        if ($value !== null) {
            $options['value'] = $value;
        }

        return new HtmlString('<input' . $this->attributes($options) . '>');
    }

    private function value(string $name, mixed $explicit): mixed
    {
        $key = $this->fieldKey($name);
        // Resolve the current request lazily. Long-running workers replace the
        // request binding between requests; retaining the first Request here
        // makes later dashboard forms read stale input and session state.
        $old = request()->old();
        if (Arr::has($old, $key)) {
            return data_get($old, $key);
        }

        if ($explicit !== null) {
            return $explicit;
        }

        if ($this->model === null) {
            return null;
        }

        return data_get($this->model, $key);
    }

    private function csrfToken(): string
    {
        if (!app()->bound('session')) {
            throw new LogicException('A session is required to render a state-changing form.');
        }

        $session = app('session');
        $token = trim((string) $session->token());
        if ($token === '') {
            $session->regenerateToken();
            $token = trim((string) $session->token());
        }
        if ($token === '') {
            throw new LogicException('A CSRF token could not be generated for the form.');
        }

        return $token;
    }

    private function resolveAction(array $options): string
    {
        if (isset($options['url'])) {
            $url = $options['url'];
            if (is_array($url)) {
                $path = array_shift($url);

                return $this->url->to($path, $url, (bool) ($options['secure'] ?? false));
            }

            return $this->url->to($url, [], (bool) ($options['secure'] ?? false));
        }

        if (isset($options['route'])) {
            $route = $options['route'];
            if (is_array($route)) {
                $name = array_shift($route);
                if (count($route) === 1 && is_array($route[0])) {
                    $route = $route[0];
                }

                return $this->url->route($name, $route);
            }

            return $this->url->route($route);
        }

        if (isset($options['action'])) {
            $action = $options['action'];
            if (is_array($action)) {
                return $this->url->action($action[0], array_slice($action, 1));
            }

            return $this->url->action($action);
        }

        return $this->url->current();
    }

    private function option(mixed $value, mixed $label, array $selectedValues): string
    {
        $attributes = ['value' => $value];
        if (in_array((string) $value, $selectedValues, true)) {
            $attributes['selected'] = true;
        }

        return '<option' . $this->attributes($attributes) . '>'
            . e((string) $label)
            . '</option>';
    }

    private function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $key => $value) {
            if (is_int($key)) {
                $key = (string) $value;
                $value = true;
            }
            if ($value === false || $value === null) {
                continue;
            }

            $key = e((string) $key);
            if ($value === true) {
                $html .= ' ' . $key . '="' . $key . '"';
                continue;
            }

            if (is_array($value)) {
                $value = implode(' ', array_map('strval', $value));
            }

            $html .= ' ' . $key . '="' . e((string) $value) . '"';
        }

        return $html;
    }

    private function fieldKey(string $name): string
    {
        return trim(str_replace(['[', ']'], ['.', ''], $name), '.');
    }

    private function fieldId(string $name): string
    {
        return trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '_', $name), '_');
    }
}
