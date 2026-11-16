<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SetLocaleTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(SetLocale::class)->get('/_test/locale', fn (): string => app()->getLocale());
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function acceptLanguageProvider(): array
    {
        return [
            'french' => ['fr', 'fr'],
            'french with region and weights' => ['fr-FR,fr;q=0.9', 'fr'],
            'english preferred over french' => ['en;q=0.9,fr;q=0.5', 'en'],
            'unsupported language' => ['de', 'en'],
            'unsupported then supported' => ['de,fr;q=0.8', 'fr'],
            'missing header' => [null, 'en'],
        ];
    }

    #[DataProvider('acceptLanguageProvider')]
    public function test_locale_is_resolved_from_the_accept_language_header(?string $header, string $expected): void
    {
        $headers = $header === null ? [] : ['Accept-Language' => $header];

        $this->get('/_test/locale', $headers)->assertOk()->assertContent($expected);
    }

    public function test_middleware_is_registered_on_the_api_group(): void
    {
        $this->assertContains(SetLocale::class, app('router')->getMiddlewareGroups()['api']);
    }
}
