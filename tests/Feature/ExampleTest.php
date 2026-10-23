<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_home_redirects_to_private_panel(): void
    {
        $this->get('/')->assertRedirect('/admin');
    }
}
