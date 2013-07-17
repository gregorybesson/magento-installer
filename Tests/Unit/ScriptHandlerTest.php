<?php
/**
 * @author Manuele Menozzi <mmenozzi@webgriffe.com>
 */

namespace Webgriffe\MagentoInstaller\Tests\Unit;


use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamDirectory;
use Webgriffe\MagentoInstaller\ScriptHandler;

class ScriptHandlerTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var vfsStreamDirectory
     */
    private $root;

    public function setUp()
    {
        $structure = array('var' => array('install.yml' => $this->getInstallYmlContent()));
        $this->root = vfsStream::setup('root', null, $structure);
    }

    public function testInstallMagento()
    {
        $event = $this->getEventMock();

        $scriptHandler = $this->getMockClass(
            'Webgriffe\MagentoInstaller\ScriptHandler',
            array('doInstall')
        );

        $scriptHandler::staticExpects($this->once())
            ->method('doInstall')
            ->with($this->getExpectedArguments());

        $scriptHandler::installMagento($event);
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    private function getEventMock()
    {
        $event = $this->getMockBuilder('Composer\Script\Event')
            ->disableOriginalConstructor()
            ->getMock();

        $event
            ->expects($this->once())
            ->method('getComposer')
            ->will($this->returnValue($this->getComposerMock()));

        return $event;
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    private function getComposerMock()
    {
        $package = $this->getPackageMock();

        $composer = $this->getMockBuilder('Composer\Composer')
            ->disableOriginalConstructor()
            ->getMock();
        $composer
            ->expects($this->once())
            ->method('getPackage')
            ->will($this->returnValue($package));

        return $composer;
    }

    /**
     * @return \PHPUnit_Framework_MockObject_MockObject
     */
    private function getPackageMock()
    {
        $extra = array(
            'install' => $this->root->getChild('var/install.yml')->url(),
        );
        $package = $this->getMockBuilder('Composer\Package\RootPackageInterface')
            ->disableOriginalConstructor()
            ->getMock();

        $package
            ->expects($this->once())
            ->method('getExtra')
            ->will($this->returnValue($extra));

        return $package;
    }

    private function getInstallYmlContent()
    {
        $content = array();
        $content[] = 'parameters:';
        $content[] = '  locale: it_IT';
        $content[] = '  timezone: Europe/Rome';
        $content[] = '  default_currency: EUR';
        $content[] = '  db_host: localhost';
        $content[] = '  db_name: magento';
        $content[] = '  db_user: magento';
        $content[] = '  db_pass: password';
        $content[] = '  url: http://magento.local/';
        $content[] = '  admin_firstname: Mario';
        $content[] = '  admin_lastname: Rossi';
        $content[] = '  admin_email: mario.rossi@foo.it';
        $content[] = '  admin_username: admin';
        $content[] = '  admin_password: password';

        return implode(PHP_EOL, $content);
    }

    private function getExpectedArguments()
    {
        return '--license_agreement_accepted "1" --skip_url_validation "1" --use_rewrites "1" '.
            '--use_secure "0" --use_secure_admin "0" --locale "it_IT" --timezone "Europe/Rome" ' .
            '--default_currency "EUR" --db_host "localhost" --db_name "magento" --db_user "magento" ' .
            '--db_pass "password" --url "http://magento.local/" --admin_firstname "Mario" --admin_lastname "Rossi" ' .
            '--admin_email "mario.rossi@foo.it" --admin_username "admin" --admin_password "password" '.
            '--secure_base_url "http://magento.local/"';
    }
}