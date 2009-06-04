#!/usr/bin/env php
<?php
// $Id$

/**
 * @file
 * drush is a PHP script implementing a command line shell for Drupal.
 *
 * @requires PHP CLI 5.2.0, or newer.
 */

// Terminate immediately unless invoked as a command line script
if (!drush_verify_cli()) {
  die('drush.php is designed to run via the command line.');
}

// Check supported version of PHP.
define('DRUSH_MINIMUM_PHP', '5.2.0');
if (version_compare(phpversion(), DRUSH_MINIMUM_PHP) < 0) {
  die('Your PHP installation is too old. Drush requires at least PHP ' . DRUSH_MINIMUM_PHP . "\n");
}

define('DRUSH_BASE_PATH', dirname(__FILE__));
define('DRUSH_COMMAND', $GLOBALS['argv'][0]);
define('DRUSH_REQUEST_TIME', microtime(TRUE));

require_once DRUSH_BASE_PATH . '/includes/environment.inc';
require_once DRUSH_BASE_PATH . '/includes/command.inc';
require_once DRUSH_BASE_PATH . '/includes/drush.inc';
require_once DRUSH_BASE_PATH . '/includes/backend.inc';
require_once DRUSH_BASE_PATH . '/includes/context.inc';

drush_set_context('argc', $GLOBALS['argc']);
drush_set_context('argv', $GLOBALS['argv']);

exit(drush_main());

/**
 * Verify that we are running PHP through the command line interface.
 *
 * This function is useful for making sure that code cannot be run via the web server,
 * such as a function that needs to write files to which the web server should not have
 * access to.
 *
 * @return
 *   A boolean value that is true when PHP is being run through the command line,
 *   and false if being run through cgi or mod_php.
 */
function drush_verify_cli() {
  return (php_sapi_name() == 'cli' || (is_numeric($_SERVER['argc']) && $_SERVER['argc'] > 0));
}

/**
 * The main Drush function.
 *
 * - Parses the command line arguments, configuration files and environment.
 * - Prepares and executes a Drupal bootstrap, if possible,
 * - Dispatches the given command.
 *
 * @return
 *   Whatever the given command returns.
 */
function drush_main() {
  $phases = _drush_bootstrap_phases();

  $return = '';
  $command_found = FALSE;

  foreach ($phases as $phase) {
    if (drush_bootstrap($phase)) {
      $command = drush_parse_command();
      if (is_array($command)) {
        if ($command['bootstrap'] == $phase && empty($command['bootstrap_errors'])) {
          drush_log(dt("Found command: !command", array('!command' => $command['command'])), 'bootstrap');
          $command_found = TRUE;
          // Dispatch the command(s).
          $return = drush_dispatch($command);
          break;
        }
      }
    }
    else {
      break;
    }
  }

  if (!$command_found) {
    // If we reach this point, we have not found either a valid or matching command.
    $args = implode(' ', drush_get_arguments());
    $drush_command = array_pop(explode('/', DRUSH_COMMAND));
    if (isset($command) && is_array($command)) {
      foreach ($command['bootstrap_errors'] as $key => $error) {
        drush_set_error($key, $error); 
      }
      drush_set_error('DRUSH_COMMAND_NOT_EXECUTABLE', dt("The command '!drush_command !args' could not be executed.", array('!drush_command' => $drush_command, '!args' => $args)));
    }
    elseif (!empty($args)) {
      drush_set_error('DRUSH_COMMAND_NOT_FOUND', dt("The command '!drush_command !args' could not be found.", array('!drush_command' => $drush_command, '!args' => $args)));
    }
    else {
      // This can occur if we get an error during _drush_bootstrap_drush_validate();
      drush_set_error('DRUSH_COULD_NOT_EXECUTE', dt("Drush could not execute."));
    }
  }

  // We set this context to let the shutdown function know we reached the end of drush_main();
  drush_set_context("DRUSH_EXECUTION_COMPLETED", TRUE);

  // After this point the drush_shutdown function will run,
  // exiting with the correct exit code.
  return $return;
}

/**
 * Shutdown function for use while Drupal is bootstrapping and to return any
 * registered errors.
 *
 * The shutdown command checks whether certain options are set to reliably
 * detect and log some common Drupal initialization errors.
 *
 * If the command is being executed with the --backend option, the script
 * will return a json string containing the options and log information
 * used by the script.
 * 
 * The command will exit with '1' if it was succesfully executed, and the 
 * result of drush_get_error() if it wasn't.
 */
function drush_shutdown() {
  // Mysteriously make $user available during sess_write(). Avoids a NOTICE.
  global $user; 

  if (!drush_get_context('DRUSH_EXECUTION_COMPLETED', FALSE)) {
    // We did not reach the end of the drush_main function, 
    // this generally means somewhere in the code a call to exit(),
    // was made. We catch this, so that we can trigger an error in 
    // those cases.
    drush_set_error("DRUSH_NOT_COMPLETED", dt("Drush command could not be completed."));
  }

  $phase = drush_get_context('DRUSH_BOOTSTRAP_PHASE');
  if (drush_get_context('DRUSH_BOOTSTRAPPING')) {
    switch ($phase) {
      case DRUSH_BOOTSTRAP_DRUPAL_FULL :
        ob_end_clean();
        _drush_log_drupal_messages();
        drush_set_error('DRUSH_DRUPAL_BOOTSTRAP_ERROR');
        break;
    }
  }

  if (drush_get_context('DRUSH_BACKEND')) {
    drush_backend_output();
  }
  elseif (drush_get_context('DRUSH_QUIET')) {
    ob_end_clean();
  }
  
  // If we are in pipe mode, emit the compact representation of the command, if available.
  if (drush_get_context('DRUSH_PIPE')) {
    drush_pipe_output();
  }
  
  exit((drush_get_error()) ? DRUSH_FRAMEWORK_ERROR : DRUSH_SUCCESS);
}

/**
 * Log the given user in to a bootstrapped Drupal site.
 *
 * @param mixed
 *   Numeric user id or user name.
 *
 * @return boolean
 *   TRUE if user was logged in, otherwise FALSE.
 */
function drush_drupal_login($drush_user) {
  global $user;
  if (drush_drupal_major_version() >= 7) {
    $user = is_numeric($drush_user) ? user_load($drush_user) : user_load_by_name($drush_user);
  }
  else {
    $user = user_load(is_numeric($drush_user) ? array('uid' => $drush_user) : array('name' => $drush_user));
  }

  if (empty($user)) {
    if (is_numeric($drush_user)) {
      $message = dt('Could not login with user ID #%user.', array('%user' => $drush_user));
    }
    else {
      $message = dt('Could not login with user account `%user\'.', array('%user' => $drush_user));
    }
    return drush_set_error('DRUPAL_USER_LOGIN_FAILED', $message);
  }

  return TRUE;
}

