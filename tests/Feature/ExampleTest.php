<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Test bahwa halaman awal Agung ATK merender 200 OK dan memuat branding serta 4 peran.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get('/');

        $response->assertStatus(200);
        $response->assertSee('AGUNG');
        $response->assertSee('ATK');
        $response->assertSee('Kuningan');
        $response->assertSee('Admin');
        $response->assertSee('Penjual');
        $response->assertSee('Kurir');
        $response->assertSee('Pelanggan');
    }
}
