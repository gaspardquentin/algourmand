<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiDocumentationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['algourmand.docs.enabled' => true]);
    }

    public function test_openapi_spec_documents_only_v1_routes(): void
    {
        $response = $this->getJson('/docs/api.json')->assertOk();

        $response->assertJsonPath('info.title', 'Algourmand API')
            ->assertJsonPath('components.securitySchemes.http.scheme', 'bearer');
        $this->assertEqualsCanonicalizing(
            ['/register', '/login', '/logout', '/user'],
            array_keys($response->json('paths')),
        );
    }

    public function test_docs_ui_is_served(): void
    {
        $this->get('/docs/api')->assertOk();
    }

    public function test_docs_are_forbidden_when_disabled_outside_local(): void
    {
        config(['algourmand.docs.enabled' => false]);

        $this->get('/docs/api.json')->assertForbidden();
    }

    public function test_docs_follow_the_flag_in_production(): void
    {
        $this->app['env'] = 'production';

        config(['algourmand.docs.enabled' => true]);
        $this->getJson('/docs/api.json')->assertOk();

        config(['algourmand.docs.enabled' => false]);
        $this->getJson('/docs/api.json')->assertForbidden();
    }
}
