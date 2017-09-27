<?php

namespace Drush\Preflight;

use Consolidation\AnnotatedCommand\Hooks\InitializeHookInterface;
use Symfony\Component\Console\Input\InputInterface;
use Consolidation\AnnotatedCommand\AnnotationData;
use Drush\Log\LogLevel;

/**
 * The RedispatchHook is installed as an init hook that runs before
 * all commands. If the commandline contains an alias or a site specification
 * that points at a remote machine, then we will stop execution of the
 * current command and instead run the command remotely.
 */
class RedispatchHook implements InitializeHookInterface
{
    public function __construct()
    {
    }

    public function initialize(InputInterface $input, AnnotationData $annotationData)
    {
        // See drush_preflight_command_dispatch; also needed are:
        //   - redispatch to a different site-local Drush on same system
        //   - site-list handling (REMOVED)
        // These redispatches need to be done regardless of the presence
        // of a @handle-remote-commands annotation.

        // If the command has the @handle-remote-commands annotation, then
        // short-circuit redispatches to remote hosts.
        if ($annotationData->has('handle-remote-commands')) {
            return;
        }
        return $this->redispatchIfRemote($input);
    }

    public function redispatchIfRemote($input)
    {
        // Determine if this is a remote command.
        if ($input->hasOption('remote-host')) {
            return $this->redispatch($input);
        }
    }

    /**
     * Called from RemoteCommandProxy::execute() to run remote commands.
     */
    public function redispatch($input)
    {
        $remote_host = $input->getOption('remote-host');
        $remote_user = $input->getOption('remote-user');

        // Get the command arguements, and shift off the Drush command.
        $redispatchArgs = \Drush\Drush::config()->get('runtime.argv');
        $drush_path = array_shift($redispatchArgs);
        $command_name = array_shift($redispatchArgs);

        \Drush\Drush::logger()->log(LogLevel::DEBUG, 'Redispatch hook {command}', ['command' => $command_name]);

        // Remove argument patterns that should not be propagated
        $redispatchArgs = $this->alterArgsForRedispatch($redispatchArgs);

        // Fetch the commandline options to pass along to the remote command.
        // The options the user provided on the commandline will be included
        // in $redispatchArgs. Here, we only need to provide those
        // preflight options that should be propagated.
        $redispatchOptions = $this->redispatchOptions($input);

        $backend_options = [
            'drush-script' => null,
            'remote-host' => $remote_host,
            'remote-user' => $remote_user,
            'additional-global-options' => [],
            'integrate' => true,
            'backend' => false,
        ];
        if ($input->isInteractive()) {
            $backend_options['#tty'] = true;
            $backend_options['interactive'] = true;
        }

        $invocations = [
            [
                'command' => $command_name,
                'args' => $redispatchArgs,
            ],
        ];
        $common_backend_options = [];
        $default_command = null;
        $default_site = [
            'remote-host' => $remote_host,
            'remote-user' => $remote_user,
            'root' => $input->getOption('root'),
            'uri' => $input->getOption('uri'),
        ];
        $context = null;

        $values = drush_backend_invoke_concurrent(
            $invocations,
            $redispatchOptions,
            $backend_options,
            $default_command,
            $default_site,
            $context
        );

        return $this->exitEarly($values);
    }

    protected function redispatchOptions(InputInterface $input)
    {
        return [];
        $result = [];
        $redispatchOptionList = [
            'root',
            'uri',
        ];
        foreach ($redispatchOptionList as $option) {
            $value = $input->hasOption($option) ? $input->getOption($option) : false;
            if ($value === true) {
                $result[$option] = true;
            } elseif (is_string($value) && !empty($value)) {
                $result[$option] = $value;
            }
        }

        return $result;
    }

    /**
     * Remove anything that is not necessary for the remote side.
     * At the moment this is limited to configuration options
     * provided via -D.
     */
    protected function alterArgsForRedispatch($redispatchArgs)
    {
        return array_filter($redispatchArgs, function ($item) {
            return strpos($item, '-D') !== 0;
        });
    }

    protected function exitEarly($values)
    {
        \Drush\Drush::logger()->log(LogLevel::DEBUG, 'Redispatch hook exit early');

        // TODO: This is how Drush exits from redispatch commands today;
        // perhaps this could be somewhat improved, though.
        // Note that RemoteCommandProxy::execute() is expecting that
        // the redispatch() method will not return, so that will need
        // to be altered if this behavior is changed.
        drush_set_context('DRUSH_EXECUTION_COMPLETED', true);
        exit($values['error_status']);
    }
}
