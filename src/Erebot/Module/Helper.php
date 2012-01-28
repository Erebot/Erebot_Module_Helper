<?php
/*
    This file is part of Erebot.

    Erebot is free software: you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation, either version 3 of the License, or
    (at your option) any later version.

    Erebot is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with Erebot.  If not, see <http://www.gnu.org/licenses/>.
*/

/**
 * \brief
 *      A module that can be used by other modules
 *      to register a method to call whenever someone
 *      asks for help on them.
 */
class   Erebot_Module_Helper
extends Erebot_Module_Base
{
    /// Token associated with this module's trigger.
    protected $_trigger;

    /// Handler used by this module to detect help requests.
    protected $_handler;

    /// Maps each module to the method that handles help requests for it.
    protected $_helpCallbacks;


    /**
     * This method is called whenever the module is (re)loaded.
     *
     * \param int $flags
     *      A bitwise OR of the Erebot_Module_Base::RELOAD_*
     *      constants. Your method should take proper actions
     *      depending on the value of those flags.
     *
     * \note
     *      See the documentation on individual RELOAD_*
     *      constants for a list of possible values.
     */
    public function _reload($flags)
    {
        if (!($flags & self::RELOAD_INIT)) {
            $registry =& $this->_connection->getModule(
                'Erebot_Module_TriggerRegistry'
            );
            $matchAny = Erebot_Utils::getVStatic($registry, 'MATCH_ANY');

            $this->_connection->removeEventHandler($this->_handler);
            $registry->freeTriggers($this->_trigger, $matchAny);
        }

        if ($flags & self::RELOAD_HANDLERS) {
            $registry = $this->_connection->getModule(
                'Erebot_Module_TriggerRegistry'
            );
            $matchAny = Erebot_Utils::getVStatic($registry, 'MATCH_ANY');

            $trigger        = $this->parseString('trigger', 'help');
            $this->_trigger = $registry->registerTriggers($trigger, $matchAny);
            if ($this->_trigger === NULL) {
                $fmt = $this->getFormatter(FALSE);
                throw new Exception($fmt->_('Could not register Help trigger'));
            }

            $this->_handler = new Erebot_EventHandler(
                new Erebot_Callable(array($this, 'handleHelp')),
                new Erebot_Event_Match_All(
                    new Erebot_Event_Match_InstanceOf(
                        'Erebot_Interface_Event_Base_TextMessage'
                    ),
                    new Erebot_Event_Match_Any(
                        new Erebot_Event_Match_TextStatic($trigger, TRUE),
                        new Erebot_Event_Match_TextWildcard($trigger.' *', TRUE)
                    )
                )
            );
            $this->_connection->addEventHandler($this->_handler);

            // Add help support to Help module.
            // This has to be done by hand, because the module
            // is not registered for this connection yet.
            $this->realRegisterHelpMethod($this, array($this, 'getHelp'));
        }
    }

    /**
     * Registers a method to call back whenever
     * someone requests help on a specific module.
     *
     * \param Erebot_Module_Base $module
     *      The module the method provides help for.
     *
     * \param Erebot_Interface_Callable $callback
     *      The method/function to call whenever
     *      someone asks for help on that particular
     *      module or a command provided by it.
     *
     * \return
     *      This method always returns TRUE.
     */
    public function realRegisterHelpMethod(
        Erebot_Module_Base          $module,
        Erebot_Interface_Callable   $callback
    )
    {
        // Works for all kinds of PHP callbacks so far...
        // (functions, [static] methods, invokable objects & closures)
        $reflector  = new ReflectionParameter($callback, 0);
        $moduleName = strtolower(get_class($module));
        $cls        = $reflector->getClass();
        if ($cls === NULL || !$cls->implementsInterface(
            'Erebot_Interface_Event_Base_MessageCapable'
        ))
            throw new Erebot_InvalidValueException('Invalid signature');

        $this->_helpCallbacks[$moduleName] = $callback;
        return TRUE;
    }

    /**
     * Provides help about this module.
     *
     * \param Erebot_Interface_Event_Base_TextMessage $event
     *      Some help request.
     *
     * \param array $words
     *      Parameters passed with the request. This is the same
     *      as this module's name when help is requested on the
     *      module itself (in opposition with help on a specific
     *      command provided by the module).
     */
    public function getHelp(
        Erebot_Interface_Event_Base_TextMessage $event,
                                                $words
    )
    {
        if ($event instanceof Erebot_Interface_Event_Base_Private) {
            $target = $event->getSource();
            $chan   = NULL;
        }
        else
            $target = $chan = $event->getChan();

        $fmt        = $this->getFormatter($chan);
        $trigger    = $this->parseString('trigger', 'help');
        $moduleName = strtolower(get_class());
        $nbArgs     = count($words);

        // "!help Helper"
        if ($nbArgs == 1 && $words[0] == $moduleName) {
            $modules = array_keys($this->_connection->getModules($chan));
            $msg = $fmt->_(
                '<b>Usage</b>: "!<var name="trigger"/> &lt;<u>Module</u>&gt; '.
                '[<u>command</u>]". Module names must start with an '.
                'uppercase letter but are not case-sensitive otherwise. '.
                'The following modules are loaded: <for from="modules" '.
                'item="module"><b><var name="module"/></b></for>.',
                array(
                    'modules' => $modules,
                    'trigger' => $trigger,
                )
            );
            $this->sendMessage($target, $msg);
            return TRUE;
        }

        if ($nbArgs < 2 || $words[1] != $trigger)
            return FALSE;

        // "!help Helper *" or just "!help"
        $msg = $fmt->_(
            '<b>Usage</b>: "!<var name="trigger"/> &lt;<u>Module</u>&gt; '.
            '[<u>command</u>]" or "!<var name="trigger"/> '.
            '&lt;<u>command</u>&gt;". Provides help about a particular '.
            'module or command. Use "!<var name="trigger"/> <var '.
            'name="this"/>" for a list of currently loaded modules.',
            array(
                'this' => get_class(),
                'trigger' => $trigger,
            )
        );
        $this->sendMessage($target, $msg);
        return TRUE;
    }

    /**
     * Handles a request for help on some module/command.
     *
     * \param Erebot_Interface_EventHandler $handler
     *      Handler that triggered this event.
     *
     * \param Erebot_Interface_Event_Base_TextMessage $event
     *      Contents of the help request (eg. name of a module
     *      or command).
     *
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function handleHelp(
        Erebot_Interface_EventHandler           $handler,
        Erebot_Interface_Event_Base_TextMessage $event
    )
    {
        if ($event instanceof Erebot_Interface_Event_Base_Private) {
            $target = $event->getSource();
            $chan   = NULL;
        }
        else
            $target = $chan = $event->getChan();

        $text       = preg_split('/\s+/', rtrim($event->getText()));
        $foo        = array_shift($text); // Consume '!help' trigger.
        $fmt        = $this->getFormatter($chan);

        // Just "!help". Emulate "!help Help help".
        if (!count($text))
            $text = array(get_class(), 'help');

        $moduleName = NULL;
        // If the first letter of the first word is in uppercase,
        // this is a request for help on a module (!help Module).
        $first = substr($text[0][0], 0, 1);
        if ($first == strtoupper($first))
            $moduleName = strtolower(array_shift($text));

        // Got request on a module, check if it exists/has a callback.
        if ($moduleName !== NULL) {
            $found = FALSE;
            $chanModules = array_keys($this->_connection->getModules($chan));
            foreach ($chanModules as $name) {
                if (!strcasecmp($name, $moduleName)) {
                    $found = TRUE;
                    break;
                }
            }

            if (!$found) {
                $msg = $fmt->_(
                    'No such module <b><var name="module"/></b>.',
                    array('module' => $moduleName)
                );
                return $this->sendMessage($target, $msg);
            }

            if (!isset($this->_helpCallbacks[$moduleName])) {
                $msg = $fmt->_(
                    'No help available on module <b><var name="module"/></b>.',
                    array('module' => $moduleName)
                );
                return $this->sendMessage($target, $msg);
            }
        }

        // Now, use the appropriate callback to handle the request.
        // If the request directly concerns a command (!help command),
        // loop through all callbacks until one handles the request.
        if ($moduleName === NULL)
            $moduleNames = array_map(
                'strtolower',
                array_keys($this->_connection->getModules($chan))
            );
        else
            $moduleNames = array($moduleName);

        foreach ($moduleNames as $modName) {
            if (!isset($this->_helpCallbacks[$modName]))
                continue;
            $callback = $this->_helpCallbacks[$modName];
            $words = $text;
            array_unshift($words, $moduleName);
            if ($callback->invoke($event, $words))
                return;
        }

        // No callback handled this request.
        // We assume no help is available.
        $msg = $fmt->_(
            'No help available on command <b><var name="command"/></b>.',
            array('command' => $event->getText()->getTokens(1))
        );
        $this->sendMessage($target, $msg);
    }
}
