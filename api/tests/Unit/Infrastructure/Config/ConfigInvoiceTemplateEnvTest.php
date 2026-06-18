<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Infrastructure\Config;

use MyInvoice\Infrastructure\Config\Config;
use PHPUnit\Framework\TestCase;

final class ConfigInvoiceTemplateEnvTest extends TestCase
{
    private string $tmpDir;
    /** @var string|false */
    private $envBackup;

    protected function setUp(): void
    {
        parent::setUp();
        $this->envBackup = getenv('MYINVOICE_INVOICE_TEMPLATE');
        $this->tmpDir = sys_get_temp_dir() . '/myinvoice-tplenv-' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0700, true);
        file_put_contents($this->tmpDir . '/cfg.php', "<?php\nreturn ['db'=>['host'=>'127.0.0.1','port'=>3306,'name'=>'x','user'=>'x','pass'=>'x']];\n");
    }

    protected function tearDown(): void
    {
        if ($this->envBackup === false) {
            putenv('MYINVOICE_INVOICE_TEMPLATE');
            unset($_ENV['MYINVOICE_INVOICE_TEMPLATE'], $_SERVER['MYINVOICE_INVOICE_TEMPLATE']);
        } else {
            putenv('MYINVOICE_INVOICE_TEMPLATE=' . $this->envBackup);
        }
        @unlink($this->tmpDir . '/cfg.php');
        @rmdir($this->tmpDir);
        parent::tearDown();
    }

    public function testEnvOverridesInvoiceTemplate(): void
    {
        putenv('MYINVOICE_INVOICE_TEMPLATE=spotted');
        $_ENV['MYINVOICE_INVOICE_TEMPLATE'] = 'spotted';
        $_SERVER['MYINVOICE_INVOICE_TEMPLATE'] = 'spotted';
        $config = Config::load($this->tmpDir);
        self::assertSame('spotted', $config->get('pdf.invoice_template'));
    }

    public function testDefaultWhenEnvAbsent(): void
    {
        putenv('MYINVOICE_INVOICE_TEMPLATE');
        unset($_ENV['MYINVOICE_INVOICE_TEMPLATE'], $_SERVER['MYINVOICE_INVOICE_TEMPLATE']);
        $config = Config::load($this->tmpDir);
        self::assertSame('invoice', $config->get('pdf.invoice_template', 'invoice'));
    }
}
