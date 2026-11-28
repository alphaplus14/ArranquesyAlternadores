<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // S-98: las pruebas anteriores a la base fija de caja describen el cuadre sin base; las de
        // S-98 (S98BaseCajaTest) la fijan explícitamente.
        config(['caja.base' => 0]);
    }
}
