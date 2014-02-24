<?php
/**
 * @author Manuele Menozzi <mmenozzi@webgriffe.com>
 */

namespace Webgriffe\MagentoInstaller;


use Composer\IO\IOInterface;
use Composer\Script\Event;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

class ScriptHandler
{

    const DATABASE_CHARACTER_SET = 'utf8';
    const DATABASE_COLLATE = 'utf8_general_ci';

    private static $mysqlPdoWrapper;

    public static function installMagento(Event $event)
    {
        $options = $event->getComposer()
            ->getPackage()
            ->getExtra();
        $parametersFile = $options['install'];
        $magentoRootDir = rtrim($options['magento-root-dir'], '/');
        $magentoSampleDir = rtrim($options['magento-sample-dir'], '/');

        if (! file_exists($magentoRootDir) || ! is_dir($magentoRootDir)) {
            throw new DirectoryNotFoundException($magentoRootDir);
        }

        if (! file_exists($parametersFile)) {
            throw new FileNotFoundException($parametersFile);
        }

        $yml = Yaml::parse($parametersFile);
        $parameters = self::getInstallParameters($yml['parameters']);

        self::$mysqlPdoWrapper = new PdoWrapper();
        $dsn = sprintf('mysql:host=%s', $parameters['db_host']);
        self::$mysqlPdoWrapper->init($dsn, $parameters['db_user'], $parameters['db_pass']);
        $query = sprintf("SHOW DATABASES LIKE '%s';", $parameters['db_name']);
        $pdoStatement = self::$mysqlPdoWrapper->query($query);

        $io = $event->getIO();

        if ($pdoStatement->rowCount() > 0) {
            $io->write(sprintf('Database \'%s\' already exists, installation skipped.', $parameters['db_name']));
            return;
        }

        if (! self::askConfirmation($io, $parameters)) {
            return;
        }

        self::createMysqlDatabase($parameters);

        // Do I install the sample data ?
        if (file_exists($magentoSampleDir) || is_dir($magentoSampleDir)) {

            if ($parameters['install_sample']) {

                $sql = file_get_contents($magentoSampleDir.'/magento_sample_data.sql');
                $sql_query = self::remove_remarks($sql);
                $sql_query = self::split_sql_file($sql_query, ';');

                self::$mysqlPdoWrapper->query("use ".$parameters['db_name']);
                foreach($sql_query as $query){
                    self::$mysqlPdoWrapper->query($query);
                }

                // Copy / paste the sample data content in /media
                self::copy($magentoSampleDir.'/media', $magentoRootDir . '/media');

                echo "Sample Data installed\n";
            }
        }


        $command = static::getInstallCommand($parameters, $magentoRootDir);
        self::executeCommand($command);
    }

    protected static function executeCommand($command)
    {
        $process = new Process(null);
        $process->setCommandLine($command);
        $process->setTimeout(300);
        $process->run(function ($type, $buffer)
        {
            echo $buffer;
        });
        if (! $process->isSuccessful()) {
            throw new \RuntimeException(sprintf('An error occurred while executing \'%s\'.', $command));
        }
    }

    private static function getInstallCommand(array $parameters, $magentoRootDir)
    {
        $arguments = array();
        foreach ($parameters as $key => $value) {
            $arguments[] = sprintf('--%s "%s"', $key, $value);
        }

        $arguments = implode(' ', $arguments);
        return sprintf('php -f %s/install.php -- %s', $magentoRootDir, $arguments);
    }

    private static function getInstallParameters(array $parameters)
    {
        return array_merge(array(
            'license_agreement_accepted' => '1',
            'skip_url_validation' => '1',
            'use_rewrites' => '1',
            'use_secure' => '0',
            'use_secure_admin' => '0'
        ), $parameters, array(
            'secure_base_url' => $parameters['url']
        ));
    }

    /**
     *
     * @param
     *            $parameters
     */
    private static function createMysqlDatabase(array $parameters)
    {
        $createDatabaseQuery = sprintf('CREATE DATABASE `%s` CHARACTER SET %s COLLATE %s;', $parameters['db_name'], self::DATABASE_CHARACTER_SET, self::DATABASE_COLLATE);
        self::$mysqlPdoWrapper->query($createDatabaseQuery);
    }

    /**
     *
     * @param IOInterface $io
     * @param
     *            $parameters
     * @return bool
     */
    private static function askConfirmation(IOInterface $io, $parameters)
    {
        if (! $io->isInteractive()) {
            return true;
        }

        $confirmation = $io->askConfirmation(sprintf('Do you want to create MySQL database \'%s\' and install Magento on it [Y,n]?', $parameters['db_name']), true);
        return $confirmation;
    }

    //
    // remove_comments will strip the sql comment lines out of an uploaded sql file
    // specifically for mssql and postgres type files in the install....
    //
    private static function remove_comments(&$output)
    {
        $lines = explode("\n", $output);
        $output = "";

        // try to keep mem. use down
        $linecount = count($lines);

        $in_comment = false;
        for ($i = 0; $i < $linecount; $i ++) {
            if (preg_match("/^\/\*/", preg_quote($lines[$i]))) {
                $in_comment = true;
            }

            if (! $in_comment) {
                $output .= $lines[$i] . "\n";
            }

            if (preg_match("/\*\/$/", preg_quote($lines[$i]))) {
                $in_comment = false;
            }
        }

        unset($lines);
        return $output;
    }

    //
    // remove_remarks will strip the sql comment lines out of an uploaded sql file
    //
    private static function remove_remarks($sql)
    {
        $lines = explode("\n", $sql);

        // try to keep mem. use down
        $sql = "";

        $linecount = count($lines);
        $output = "";

        for ($i = 0; $i < $linecount; $i ++) {
            if (($i != ($linecount - 1)) || (strlen($lines[$i]) > 0)) {
                if (isset($lines[$i][0]) && $lines[$i][0] != "#") {
                    $output .= $lines[$i] . "\n";
                } else {
                    $output .= "\n";
                }
                // Trading a bit of speed for lower mem. use here.
                $lines[$i] = "";
            }
        }

        return $output;
    }

    //
    // split_sql_file will split an uploaded sql file into single sql statements.
    // Note: expects trim() to have already been run on $sql.
    //
    private static function split_sql_file($sql, $delimiter)
    {
        // Split up our string into "possible" SQL statements.
        $tokens = explode($delimiter, $sql);

        // try to save mem.
        $sql = "";
        $output = array();

        // we don't actually care about the matches preg gives us.
        $matches = array();

        // this is faster than calling count($oktens) every time thru the loop.
        $token_count = count($tokens);
        for ($i = 0; $i < $token_count; $i ++) {
            // Don't wanna add an empty string as the last thing in the array.
            if (($i != ($token_count - 1)) || (strlen($tokens[$i] > 0))) {
                // This is the total number of single quotes in the token.
                $total_quotes = preg_match_all("/'/", $tokens[$i], $matches);
                // Counts single quotes that are preceded by an odd number of backslashes,
                // which means they're escaped quotes.
                $escaped_quotes = preg_match_all("/(?<!\\\\)(\\\\\\\\)*\\\\'/", $tokens[$i], $matches);

                $unescaped_quotes = $total_quotes - $escaped_quotes;

                // If the number of unescaped quotes is even, then the delimiter did NOT occur inside a string literal.
                if (($unescaped_quotes % 2) == 0) {
                    // It's a complete sql statement.
                    $output[] = $tokens[$i];
                    // save memory.
                    $tokens[$i] = "";
                } else {
                    // incomplete sql statement. keep adding tokens until we have a complete one.
                    // $temp will hold what we have so far.
                    $temp = $tokens[$i] . $delimiter;
                    // save memory..
                    $tokens[$i] = "";

                    // Do we have a complete statement yet?
                    $complete_stmt = false;

                    for ($j = $i + 1; (! $complete_stmt && ($j < $token_count)); $j ++) {
                        // This is the total number of single quotes in the token.
                        $total_quotes = preg_match_all("/'/", $tokens[$j], $matches);
                        // Counts single quotes that are preceded by an odd number of backslashes,
                        // which means they're escaped quotes.
                        $escaped_quotes = preg_match_all("/(?<!\\\\)(\\\\\\\\)*\\\\'/", $tokens[$j], $matches);

                        $unescaped_quotes = $total_quotes - $escaped_quotes;

                        if (($unescaped_quotes % 2) == 1) {
                            // odd number of unescaped quotes. In combination with the previous incomplete
                            // statement(s), we now have a complete statement. (2 odds always make an even)
                            $output[] = $temp . $tokens[$j];

                            // save memory.
                            $tokens[$j] = "";
                            $temp = "";

                            // exit the loop.
                            $complete_stmt = true;
                            // make sure the outer loop continues at the right point.
                            $i = $j;
                        } else {
                            // even number of unescaped quotes. We still don't have a complete statement.
                            // (1 odd and 1 even always make an odd)
                            $temp .= $tokens[$j] . $delimiter;
                            // save memory.
                            $tokens[$j] = "";
                        }
                    } // for..
                } // else
            }
        }

        return $output;
    }
    
    protected static function copy($source, $dest)
    {
        foreach (
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($source, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST) as $item
        ) {
                if ($item->isDir()) {
                    mkdir($dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
                } else {
                    copy($item, $dest . DIRECTORY_SEPARATOR . $iterator->getSubPathName());
                }
            }
    
    }
}