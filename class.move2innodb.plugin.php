<?php

$PluginInfo['move2InnoDB'] = [
    'Name' => 'Move to InnoDB',
    'Description' => 'Changes storage engine of Discussion and Comment table to InnoDB.',
    'Version' => '0.1',
    'HasLocale' => true,
    'Author' => 'Robin Jurinka',
    'AuthorUrl' => 'http://vanillaforums.org/profile/r_j',
    'RequiredApplications' => ['Vanilla' => '>=2.2'],
    'SettingsPermission' => ['Garden.Settings.Manage'],
    'SettingsUrl' => 'settings/move2innodb',
    'MobileFriendly' => true,
    'License' => 'MIT'
];

class Move2InnoDBPlugin extends Gdn_Plugin {
    /**
     * Make sure changes will not happen accidently when plugin is activated.
     *
     * @return void.
     */
    public function setup() {
        touchConfig('move2InnoDB.IKnowWhatIDo', false);
        $this->structure('InnoDB');
    }

    /**
     * Give admins the possibility to start db change manually.
     *
     * Such changes should better be thought over twice.
     *
     * @param settingsController $sender Calling controller instance.
     * @return void.
     */
    public function settingsController_move2innodb_create($sender) {
        $sender->permission('Garden.Settings.Manage');
        $sender->title(t('Move to InnoDB Setup'));
        $sender->addSideMenu('dashboard/settings/plugins');
        $sender->description('Some Description');

        $options = [];
        $description = <<< EOS
<h3><strong>Please</strong> don't use this plugin if you do not know what you are doing!</h4>
Read first about the advantages of InnoDB, the differences between search results in InnoDB and MyISAM tables and the impact that changing the engine used in a table might have.
<br>If anything unexpected happens, deactivate this plugin and run /utility/strucure.
EOS;
        // Prevent users from proceeding when MySQL version is wrong.
        if (!$this->fulltextIsAvailable()) {
            $description = 'Wrong version of MySQL. Nothing will happen here...';
            $options = [
                'class' => 'Hidden',
                'disabled' => 'disabled'
            ];
        }

        // Configure settings module.
        $configurationModule = new ConfigurationModule($sender);
        $configurationModule->initialize(
            [
                'move2InnoDB.UseInnoDB' => [
                    'Control' => 'CheckBox',
                    'LabelCode' => 'Use InnoDB for Discussion and Comment table',
                    'Description' => $description,
                    'Default' => false,
                    'Options' => $options
                ],
            ]
        );

        if ($sender->Form->authenticatedPostBack()) {
            if ($sender->Form->getValue('move2InnoDB.UseInnoDB') == true) {
                saveToConfig('move2InnoDB.IKnowWhatIDo', true);
                $sender->StatusMessage = $this->structure('InnoDB');
            } else {
                $sender->StatusMessage = $this->structure('MyISAM');
                saveToConfig('move2InnoDB.IKnowWhatIDo', false);
            }
        }
        $configurationModule->renderAll();
    }

    /**
     * Checks MySQL version to decide whether fulltext search is available.
     *
     * Fulltext search in InnoDB tables is available since version 5.6.4.
     *
     * @return bool Whether fulltext is supported or not.
     */
    public function fulltextIsAvailable() {
        // Check the version number of mysql.
        $MySQLVersion = Gdn::sql()
            ->select('VERSION() as Version')
            ->get()
            ->firstRow();
        $versionNumber = preg_replace(
            '/([^\d\.].*)/',
            '',
            $MySQLVersion->Version
        );
        if (version_compare($versionNumber, '5.6.4', '>=')) {
            return true;
        }

        return false;
    }

    /**
     * Change engine used for tables Discussion and Comment.
     *
     * Based on config settings, this function will change table engine to
     * InnoDB. Also has the option to switch it back to MyISAM.
     *
     * @param string $engine Either InnoDB or MyISAM.
     * @return [type]         [description]
     */
    public function structure($engine = 'InnoDB') {
        // Check that admin really wants this change to happen.
        if (c('move2InnoDB.IKnowWhatIDo') !== true) {
            return 'Sorry Dude, you don\'t know what you are doing...';
        }

        if (!$this->fulltextIsAvailable()) {
            return 'Please come back after you have upgraded MySQL!';
        }

        // Only accept MyISAM and InnoDB
        if (strtolower($engine) != 'myisam' && strtolower($engine) != 'innodb') {
            return "{$engine} is an unsupported storage engine!";
        }

        // Get information about the current storage engine.
        $prefix = Gdn::database()->structure()->databasePrefix();
        $infoQuery = 'SELECT table_name, engine ';
        $infoQuery .= 'FROM INFORMATION_SCHEMA.TABLES ';
        $infoQuery .= 'WHERE table_schema = DATABASE()';
        $infoQuery .= "AND table_name IN ('{$prefix}Discussion', '{$prefix}Comment')";
        $tables = Gdn::sql()->query($infoQuery);

        $result = '';
        // Loop through tables and only change if needed.
        foreach ($tables as $table) {
            if (strtolower($table->engine) != strtolower($engine)) {
                Gdn::sql()->query("ALTER TABLE {$table->table_name} ENGINE={$engine}; \r\n");
                $result .= "Engine of {$table->table_name} has been set from {$table->engine} to {$engine}.</br>";
            }
        }

        if ($result === '') {
            $result = 'No table has been changed.';
        } else {
            $result = substr($result, 0, -5);
        }
        return $result;
    }
}
