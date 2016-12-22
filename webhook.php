<?php
require_once __DIR__.'/vendor/autoload.php';

use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

header('Content-Type: text/html; charset=utf-8');

if (!file_exists(__DIR__.'/config.yml')) {
    echo "Please, define your satis configuration in a config.yml file.\nYou can use the config.yml.dist as a template.";
    exit(-1);
}

$defaults = array(
    'bin' => 'bin/satis',
    'options' => '',
    'json' => 'satis.json',
    'webroot' => 'web/',
    'user' => null,
    'token' => null,
);
$config = Yaml::parse(__DIR__.'/config.yml');
$config = array_merge($defaults, $config);

if ($config['token']) {
    if (!isset($_SERVER['HTTP_X_GITLAB_TOKEN']) || $_SERVER['HTTP_X_GITLAB_TOKEN'] != $config['token']) {
        header("{$_SERVER['SERVER_PROTOCOL']} 403 Forbidden", true, 403);
        exit(1);
    }
}

$repositoryUrl = null;
$gitData = Yaml::parse(file_get_contents('php://input'));
if (is_array($gitData) && isset($gitData['project'], $gitData['project']['git_ssh_url'])) {
    $repositoryUrl = $gitData['project']['git_ssh_url'];
}

$errors = array();
if (!file_exists($config['bin'])) {
    $errors[] = 'The Satis bin could not be found.';
}

if (!file_exists($config['json'])) {
    $errors[] = 'The satis.json file could not be found.';
}

if (!file_exists($config['webroot'])) {
    $errors[] = 'The webroot directory could not be found.';
}

if (!empty($errors)) {
    header("{$_SERVER['SERVER_PROTOCOL']} 500 Internal Server Error", true, 500);
    echo 'The build cannot be run due to some errors. Please, review them and check your config.yml:'."\n";
    foreach ($errors as $error) {
        echo '- '.$error."\n";
    }
    exit(-1);
}

$command = sprintf('%s %s build %s %s', $config['bin'], $config['options'], $config['json'], $config['webroot']);
if (null !== $config['user']) {
    $command = sprintf('sudo -u %s -i %s', $config['user'], $command);
}

if (null !== $repositoryUrl) {
    $command .= ' --repository-url=' . escapeshellarg($repositoryUrl);
}

function execute_build() {
    global $command, $config;
    do {
        $lock_process = fopen($process_file = __DIR__.'/process.lock', "w");
        if (!flock($lock_process, LOCK_EX | LOCK_NB)) {
            $lock_wait = fopen($wait_file = __DIR__.'/wait.lock', 'w');
            if (!flock($lock_wait, LOCK_EX | LOCK_NB)) {
                fclose($lock_wait);
                fclose($lock_process);
                echo "There is already a build-process waiting. Skipping.";
                return;
            }
            echo "There is already a build-process running. Waiting...";
            if (!flock($lock_process, LOCK_EX)) { // wait
                echo 'E: Failed to aquire lock';
                exit(1);
            }
            flock($lock_wait, LOCK_UN);
            unlink($wait_file); 
            fclose($lock_wait);

            flock($lock_process, LOCK_UN);
            fclose($lock_process);
            $lock_process = null;
        }
    } while (!$lock_process);
    fwrite($lock_process, getmypid());
    $process = new Process($command);
    if (isset($config['env']))
        $process->setEnv($config['env']);

    $exitCode = $process->run(function ($type, $buffer) {
        if ('err' === $type) {
            echo 'E: ', $buffer, PHP_EOL;
            error_log($buffer);
        } else {
            echo '.';
        }
    });

    flock($lock_process, LOCK_UN);
    unlink($process_file);
    fclose($lock_process);

    echo "\n\n" . ($exitCode === 0 ? 'Successful rebuild!' : 'Oops! An error occured!') . "\n";
}

if (!empty($_SERVER['HTTP_X_GITLAB_EVENT'])) {
    // Responde as quick as possible
    ini_set('implicit_flush', true);
    ignore_user_abort(true);
    header("Connection: close", true);
    header("Content-Length: 2", true);
    ob_end_flush();
    //register_shutdown_function("execute_build");
    if (function_exists('fastcgi_finish_request'))
        fastcgi_finish_request();
    echo "ok";
    flush();
    echo str_pad("", 64*1024);flush();
    execute_build();
    exit(0);
} else {
    execute_build();
}
