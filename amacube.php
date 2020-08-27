<?php

class amacube extends rcube_plugin
{
    // All tasks excluding 'login' and 'logout'
    public $task 		= '?(?!login|logout).*';
    private $rc;
    private $amacube;
    public $ama_admin;
    public $quarantine_msg;
    public $msg_headers_raw = '';

    public function init()
    {
        $this->rc = rcmail::get_instance();
        $this->amacube = new stdClass;
        // Load plugin config
        $this->load_config();
        // Amacube storage on rcmail instance
        $this->rc->amacube = new stdClass;
        $this->rc->amacube->errors = array();
        $this->rc->amacube->feedback = array();
        // Check accounts database for catchall enabled
        if ($this->rc->config->get('amacube_accounts_db_dsn')) {
            include_once('AccountConfig.php');
            $this->amacube->account = new AccountConfig($this->rc->config->get('amacube_accounts_db_dsn'));
            // Check for account filter
            if ($this->amacube->account->initialized && isset($this->amacube->account->filter)) {
                // Store on rcmail instance
                $this->rc->amacube->filter 		= $this->amacube->account->filter;
            }
            // Check for account catchall
            if ($this->amacube->account->initialized && isset($this->amacube->account->catchall)) {
                // Store on rcmail instance
                $this->rc->amacube->catchall 	= $this->amacube->account->catchall;
            }
        }
        // Load amavis config
        include_once('AmavisConfig.php');
        $this->amacube->config = new AmavisConfig($this->rc->config->get('amacube_db_dsn'));
        // Check for user & auto create option (disable plugin)
        if (!$this->amacube->config->initialized && $this->rc->config->get('amacube_auto_create_user') !== true) {
            return;
        }
        // Check for writing default user & config
        if (!$this->amacube->config->initialized && $this->rc->config->get('amacube_auto_create_user') === true) {
            // Check accounts database for filter enabled
            if (isset($this->rc->amacube->filter) && $this->rc->amacube->filter == false) {
                return;
            }
            // Write default user & config
            if ($this->amacube->config->write_to_db()) {
                $this->rc->amacube->feedback[] = array('type' => 'confirmation', 'message' => 'policy_default_message');
            }
        }

        $this->ama_admin = false;
        foreach ($this->rc->config->get('amacube_amavis_admins') as $s_admin) {
            if (strtolower($s_admin) == strtolower($this->rc->user->data['username'])) {
                $this->ama_admin = true;
            }
        }

        // Add localization
        $this->add_texts('localization/', true);
        // Register tasks & actions
        $this->register_action('plugin.amacube-settings', array($this, 'settings_init'));
        $this->register_task('quarantine');
        $this->register_action('amacube-quarantine', array($this, 'quarantine_init'));
        $this->register_action('amacube-msgview', array($this, 'quarantine_msgview'));
/*
            error_log("stage:register GET array " . print_r($_GET,true));
            error_log("stage:register skin path ".$this->rc->output->get_skin_path());
            error_log("stage:register local skin path ".$this->local_skin_path());
*/

        // Initialize GUI
        $this->add_hook('startup', array($this, 'gui_init'));
        // Send feedback
        $this->feedback();
    }
    // Initialize GUI
    public function gui_init()
    {
        $this->rc = rcmail::get_instance();
        $this->add_hook('settings_actions', array($this, 'settings_actions'));

        // Add taskbar button
        $this->add_button(array(
            'command'    => 'quarantine',
            'class'      => 'button-quarantine',
            'classsel'   => 'button-quarantine button-selected',
            'innerclass' => 'button-inner',
            'label'      => 'amacube.quarantine',
        ), 'taskbar');
        // Add javascript
        $this->include_script('amacube.js');
        // Add stylesheet
        $skin_path = $this->local_skin_path();
        if (is_file($this->home . "/$skin_path/amacube.css")) {
            $this->include_stylesheet("$skin_path/amacube.css");
        }
    }

    // Register as settings action
    public function settings_actions($args)
    {
        $args['actions'][] = array(
        'action' => 'plugin.amacube-settings',
        'class' => 'filter-settings',
        'label' => 'amacube.filter_settings_pagetitle',
        'title' => 'amacube.filter_settings_pagetitle',
        'domain' => 'amacube'
        );

        return $args;
    }

    // Initialize settings task
    public function settings_init()
    {
        $this->rc = rcmail::get_instance();
        // Use standard plugin page template
        $this->register_handler('plugin.body', array($this, 'settings_display'));
        $this->rc->output->set_pagetitle(rcube_utils::rep_specialchars_output($this->gettext('filter_settings_pagetitle'), 'html', 'strict', true));
        $this->rc->output->send('plugin');
    }
    // Initialize quarantine task
    public function quarantine_init()
    {
        if (rcube_utils::get_input_value('_remote', rcube_utils::INPUT_POST, false) == 1) {
            // Client pagination request
            $this->quarantine_display(true);
        } else {
            // Client page request
            $this->register_handler('plugin.countdisplay', array($this, 'quarantine_display_count'));
            $this->register_handler('plugin.body', array($this, 'quarantine_display'));
            $this->rc->output->set_pagetitle(rcube_utils::rep_specialchars_output($this->gettext('quarantine_pagetitle'), 'html', 'strict', true));
            // Use amacube quarantine page template
            $this->rc->output->send('amacube.quarantine');
        }
    }
    // Display settings action
    public function settings_display()
    {
        $this->rc = rcmail::get_instance();
        // Include settings class
        if (!$this->amacube->config) {
            include_once('AmavisConfig.php');
            $this->amacube->config = new AmavisConfig($this->rc->config->get('amacube_db_dsn'));
        }
        // Parse form
        if (rcube_utils::get_input_value('_token', rcube_utils::INPUT_POST, false)) {
            $this->settings_post();
        }

        // Create output
        $output = '';
        // Add header to output
        $output .= html::tag('div', array('id' => 'prefs-title', 'class' => 'boxtitle'), rcube_utils::rep_specialchars_output($this->gettext('filter_settings_pagetitle'), 'html', 'strict', true));

        // Create output : table (checks)
        $output_table = new html_table(array('cols' => 2, 'cellpadding' => 3, 'class' => 'propform'));
        // Create output : table : checkbox : spam check
        $output_table->add('title', html::label('activate_spam_check', $this->gettext('spam_check')));
        $output_table->add('', $this->_show_checkbox('activate_spam_check', $this->amacube->config->is_active('spam')));
        // Create output : table : checkbox : virus check
        $output_table->add('title', html::label('activate_virus_check', $this->gettext('virus_check')));
        $output_table->add('', $this->_show_checkbox('activate_virus_check', $this->amacube->config->is_active('virus')));
        // Create output : fieldset
        $output_legend = html::tag('legend', null, $this->gettext('section_checks'));
        $output_fieldset = html::tag('fieldset', array('class' => 'checks'), $output_legend.$output_table->show());
        // Create output : activate
        $output_checks = $output_fieldset;

        // Create output : table (delivery)
        $output_table = new html_table(array('cols' => 2, 'cellpadding' => 3, 'class' => 'propform'));
        // Create output : table : radios : spam
        $output_table->add('title', $this->gettext('spam_delivery'));
        $string = '';
        $string .= $this->_show_radio('spam_delivery_deliver', 'spam_delivery', 'deliver', $this->amacube->config->is_delivery('spam', 'deliver')).' ';
        $string .= html::label('spam_delivery_deliver', $this->gettext('deliver'));
        $string .= $this->_show_radio('spam_delivery_quarantine', 'spam_delivery', 'quarantine', $this->amacube->config->is_delivery('spam', 'quarantine')).' ';
        $string .= html::label('spam_delivery_quarantine', $this->gettext('quarantine'));
        $string .= $this->_show_radio('spam_delivery_discard', 'spam_delivery', 'discard', $this->amacube->config->is_delivery('spam', 'discard'));
        $string .= html::label('spam_delivery_discard', $this->gettext('discard'));
        $output_table->add('', $string);
        // Create output : table : radios : virus
        $output_table->add('title', $this->gettext('virus_delivery'));
        $string = '';
        $string .= $this->_show_radio('virus_delivery_deliver', 'virus_delivery', 'deliver', $this->amacube->config->is_delivery('virus', 'deliver')).' ';
        $string .= html::label('virus_delivery_deliver', $this->gettext('deliver'));
        $string .= $this->_show_radio('virus_delivery_quarantine', 'virus_delivery', 'quarantine', $this->amacube->config->is_delivery('virus', 'quarantine')).' ';
        $string .= html::label('virus_delivery_quarantine', $this->gettext('quarantine'));
        $string .= $this->_show_radio('virus_delivery_discard', 'virus_delivery', 'discard', $this->amacube->config->is_delivery('virus', 'discard'));
        $string .= html::label('virus_delivery_discard', $this->gettext('discard'));
        $output_table->add('', $string);
        // Create output : table : radios : banned
        $output_table->add('title', $this->gettext('banned_delivery'));
        $string = '';
        $string .= $this->_show_radio('banned_delivery_deliver', 'banned_delivery', 'deliver', $this->amacube->config->is_delivery('banned', 'deliver')).' ';
        $string .= html::label('banned_delivery_deliver', $this->gettext('deliver'));
        $string .= $this->_show_radio('banned_delivery_quarantine', 'banned_delivery', 'quarantine', $this->amacube->config->is_delivery('banned', 'quarantine')).' ';
        $string .= html::label('banned_delivery_quarantine', $this->gettext('quarantine'));
        $string .= $this->_show_radio('banned_delivery_discard', 'banned_delivery', 'discard', $this->amacube->config->is_delivery('banned', 'discard'));
        $string .= html::label('banned_delivery_discard', $this->gettext('discard'));
        $output_table->add('', $string);
        // Create output : table : radios : bad_header
        $output_table->add('title', $this->gettext('bad_header_delivery'));
        $string = '';
        $string .= $this->_show_radio('badheader_delivery_deliver', 'badheader_delivery', 'deliver', $this->amacube->config->is_delivery('bad_header', 'deliver')).' ';
        $string .= html::label('badheader_delivery_deliver', $this->gettext('deliver'));
        $string .= $this->_show_radio('badheader_delivery_quarantine', 'badheader_delivery', 'quarantine', $this->amacube->config->is_delivery('bad_header', 'quarantine')).' ';
        $string .= html::label('badheader_delivery_quarantine', $this->gettext('quarantine'));
        $string .= $this->_show_radio('badheader_delivery_discard', 'badheader_delivery', 'discard', $this->amacube->config->is_delivery('bad_header', 'discard'));
        $string .= html::label('badheader_delivery_discard', $this->gettext('discard'));
        $output_table->add('', $string);


        // Create output : fieldset
        $output_legend = html::tag('legend', null, $this->gettext('section_delivery'));
        $output_fieldset = html::tag('fieldset', array('class' => 'delivery'), $output_legend.$output_table->show());
        // Create output : quarantine
        $output_delivery = $output_fieldset;

        // Create output : table (levels)
        $output_table = new html_table(array('cols' => 2, 'cellpadding' => 3, 'class' => 'propform'));
        // Create output : table : input : sa_tag2_level
        $output_table->add('title', html::label('spam_tag2_level', $this->gettext('spam_tag2_level')));
        $output_table->add('', $this->_show_inputfield('spam_tag2_level', $this->amacube->config->policy_setting['spam_tag2_level']));
        // Create output : table : input : sa_kill_level
        $output_table->add('title', html::label('spam_kill_level', $this->gettext('spam_kill_level')));
        $output_table->add('', $this->_show_inputfield('spam_kill_level', $this->amacube->config->policy_setting['spam_kill_level']));
        // Create output : table : input : sa_cutoff_level
        $output_table->add('title', html::label('spam_quarantine_cutoff_level', $this->gettext('spam_quarantine_cutoff_level')));
        $output_table->add('', $this->_show_inputfield('spam_quarantine_cutoff_level', $this->amacube->config->policy_setting['spam_quarantine_cutoff_level']));
        // Create output : fieldset
        $output_legend = html::tag('legend', null, $this->gettext('section_levels'));
        $output_fieldset = html::tag('fieldset', array('class' => 'levels'), $output_legend.$output_table->show());
        // Create output : levels
        $output_levels = $output_fieldset;

        // Create output : button
        $output_button = html::div('footerleft formbuttons floating', $this->rc->output->button(array(
            'command' => 'plugin.amacube-settings-post',
            'type' => 'input',
            'class' => 'button mainaction',
            'label' => 'save'
        )));


        // Add form to container and container to output
        $output_form .= html::div(array('id' => 'preferences-details', 'class' => 'boxcontent'), $this->rc->output->form_tag(array(
            'id' => 'amacubeform',
            'name' => 'amacubeform',
            'class' => 'propform',
            'method' => 'post',
            'action' => './?_task=settings&_action=plugin.amacube-settings',
        ), $output_checks.$output_delivery.$output_levels));
        // Add labels to client
        $this->rc->output->add_label(
            'amacube.activate_spam_check',
            'amacube.activate_virus_check',
            'amacube.activate_spam_quarantine',
            'amacube.activate_virus_quarantine',
            'amacube.activate_banned_quarantine',
            'amacube.spam_tag2_level',
            'amacube.spam_kill_level'
        );
        // Add form to client
        $this->rc->output->add_gui_object('amacubeform', 'amacubeform');
        // Add button to output
        $output_form .= $output_button;
        $output .= html::div(array('id' => 'preferences-wrapper', 'class' => 'contentbox'), $output_form);
        $output = html::div(array('id' => 'amacube-prefbox', 'class' => 'scrollable'), $output);
        // Send feedback
        $this->feedback();
        // Return output
        return $output;
    }

    // Save settings action
    public function settings_post()
    {
        // Get the checks post vars
        $activate_spam_check 			= rcube_utils::get_input_value('activate_spam_check', rcube_utils::INPUT_POST, false);
        $activate_virus_check 			= rcube_utils::get_input_value('activate_virus_check', rcube_utils::INPUT_POST, false);
        // Get the levels post vars
        $spam_tag2_level 				= rcube_utils::get_input_value('spam_tag2_level', rcube_utils::INPUT_POST, false);
        $spam_kill_level 				= rcube_utils::get_input_value('spam_kill_level', rcube_utils::INPUT_POST, false);
        $spam_quarantine_cutoff_level	= rcube_utils::get_input_value('spam_quarantine_cutoff_level', rcube_utils::INPUT_POST, false);
        // Apply the checks post vars
        if (!empty($activate_spam_check)) {
            $this->amacube->config->policy_setting['bypass_spam_checks'] = false;
        } else {
            $this->amacube->config->policy_setting['bypass_spam_checks'] = true;
        }
        if (!empty($activate_virus_check)) {
            $this->amacube->config->policy_setting['bypass_virus_checks'] = false;
        } else {
            $this->amacube->config->policy_setting['bypass_virus_checks'] = true;
        }
        // Apply the delivery post vars
        foreach (array('spam_delivery','virus_delivery','banned_delivery','badheader_delivery') as $input) {
            $method 	= rcube_utils::get_input_value($input, rcube_utils::INPUT_POST, false);
            if ($method) {
                $delivery 	= explode('_', $input);
                $delivery 	= $delivery[0];
                if ($delivery == 'banned') {
                    $lover = $delivery.'_files';
                } elseif ($delivery == 'badheader') {
                    $lover = 'bad_header';
                    $delivery = 'bad_header';
                } else {
                    $lover = $delivery;
                }
                switch ($method) {
                    case 'deliver':
                        $this->amacube->config->policy_setting[$lover.'_lover'] = true;
                        $this->amacube->config->policy_setting[$delivery.'_quarantine_to'] = false;
                        break;
                    case 'quarantine':
                        $this->amacube->config->policy_setting[$lover.'_lover'] = false;
                        $this->amacube->config->policy_setting[$delivery.'_quarantine_to'] = true;
                        break;
                    case 'discard':
                        $this->amacube->config->policy_setting[$lover.'_lover'] = false;
                        $this->amacube->config->policy_setting[$delivery.'_quarantine_to'] = false;
                        break;
                }
            }
        }
        // Apply the levels post vars
        if (!is_numeric($spam_tag2_level) || $spam_tag2_level < -20 || $spam_tag2_level > 20) {
            $this->rc->amacube->errors[] = 'spam_tag2_level_error';
        } else {
            $this->amacube->config->policy_setting['spam_tag2_level'] = $spam_tag2_level;
        }
        if (!is_numeric($spam_kill_level) || $spam_kill_level < -20 || $spam_kill_level > 20) {
            $this->rc->amacube->errors[] = 'spam_kill_level_error';
        } else {
            $this->amacube->config->policy_setting['spam_kill_level'] = $spam_kill_level;
        }
        if (!is_numeric($spam_quarantine_cutoff_level) || $spam_quarantine_cutoff_level < $this->amacube->config->policy_setting['spam_kill_level'] || $spam_kill_level > 1000) {
            $this->rc->amacube->errors[] = 'spam_quarantine_cutoff_level_error';
        } else {
            $this->amacube->config->policy_setting['spam_quarantine_cutoff_level'] = $spam_quarantine_cutoff_level;
        }
        // Verify policy config
        if ($this->amacube->config->verify_policy_array() && $this->amacube->config->write_to_db()) {
            $this->rc->amacube->feedback[] = array('type' => 'confirmation', 'message' => 'config_saved');
        }
    }

    // Display quarantine task
    // Used to display entire page or specified range (ajax pagination)
    public function quarantine_display($ajax = false)
    {
        $ajax = ($ajax === true) ? true : false;
        // Include quarantine class
        include_once('AmavisQuarantine.php');
        $this->amacube->quarantine = new AmavisQuarantine(
            $this->rc->config->get('amacube_db_dsn'),
            $this->rc->config->get('amacube_amavis_host'),
            $this->rc->config->get('amacube_amavis_port')
        );
        // Parse form
        if (rcube_utils::get_input_value('_token', rcube_utils::INPUT_POST, false)) {
            $this->quarantine_post();
        }
        $pagination = array();
        if (!$ajax) {
            $output 				= '';
            // Get all quarantines (0:0)
            // Used to calculate pagination based on total amount of quarantined messages
            $pagination['start']	= 0;
            $pagination['size']		= 0;
        } else {
            $output 				= array();
            // Get paged quarantines
            $pagination['current']	= rcube_utils::get_input_value('page', rcube_utils::INPUT_POST, false) ?: 1;
            $pagination['total'] 	= rcube_utils::get_input_value('msgcount', rcube_utils::INPUT_POST, false);
            if (!$pagination['current'] || !$pagination['total']) {
                return;
            }

            $pagination['current']	= (int) $pagination['current'];
            $pagination['total'] 	= (int) $pagination['total'];
            $pagination['size']		= $this->rc->config->get('mail_pagesize');
            $pagination['count']	= ceil(($pagination['total'] / $pagination['size']));
            $pagination['start']	= (($pagination['current'] * $pagination['size']) - $pagination['size']);
            $pagination['stop']		= ($pagination['start'] + $pagination['size']);
        }
        $quarantines = $this->amacube->quarantine->list_quarantines($pagination['start'], $pagination['size']);
        if (!is_array($quarantines)) {
            // Send feedback
            $this->feedback();
            // Return on error
            return;
        }
        if (count($quarantines) == 0) {
            $this->amacube->feedback[] = array('type' => 'notice', 'message' => 'quarantine_no_result');
        }
        if (!$ajax) {
            $pagination['current'] 	= 1;
            $pagination['size']		= $this->rc->config->get('mail_pagesize');
            $pagination['count']	= ceil((count($quarantines) / $pagination['size']));
            $pagination['start']	= (($pagination['current'] * $pagination['size']) - $pagination['size']);
            $pagination['stop']		= ($pagination['start'] + $pagination['size']);
            $pagination['total'] 	= count($quarantines);
        }
        // Pagination string
        $pagination['begin'] 		= ($pagination['start']+1);
        $pagination['end'] 			= ($pagination['total'] <= $pagination['size']) ? $pagination['total'] : (($pagination['stop'] > $pagination['total']) ? $pagination['total'] : $pagination['stop']);
        if (count($quarantines) == 0) {
            $string					= rcube_utils::rep_specialchars_output($this->gettext('quarantine_no_result'), 'html', 'strict', true);
        } else {
            $string					= rcube_utils::rep_specialchars_output($this->gettext('messages'), 'html', 'strict', true).' '.$pagination['begin'].' '.rcube_utils::rep_specialchars_output($this->gettext('to'), 'html', 'strict', true).' '.$pagination['end'].' '.rcube_utils::rep_specialchars_output($this->gettext('of'), 'html', 'strict', true).' '.$pagination['total'];
        }
        if (!$ajax) {
            // Store locally for template use (js include not loaded yet; command unavailable)
            $this->rc->amacube->pagecount_string = $string;
        } else {
            $this->rc->output->command('amacube.messagecount', $string);
        }
        // Pagination env
        $this->rc->output->set_env('page', $pagination['current']);
        $this->rc->output->set_env('pagecount', $pagination['count']);
        $this->rc->output->set_env('msgcount', $pagination['total']);
        // Create output
        if (!$ajax) {
            // Create output : header table
            if ($this->ama_admin === true) {
                $messages_table = new html_table(array(
                    'cols' 				=> 8,
                    'id'				=> 'messagelist',
                    'class' 			=> 'records-table messagelist sortheader fixedheader quarantine-messagelist'
                ));
            } else {
                $messages_table = new html_table(array(
                    'cols' 				=> 7,
                    'id'				=> 'messagelist',
                    'class' 			=> 'records-table messagelist sortheader fixedheader quarantine-messagelist'
                ));
            }
            // Create output : table : headers
            $messages_table->add_header('release', rcube_utils::rep_specialchars_output($this->gettext('release'), 'html', 'strict', true));
            $messages_table->add_header('delete', rcube_utils::rep_specialchars_output($this->gettext('delete'), 'html', 'strict', true));
            $messages_table->add_header('received', rcube_utils::rep_specialchars_output($this->gettext('received'), 'html', 'strict', true));
            $messages_table->add_header('subject', rcube_utils::rep_specialchars_output($this->gettext('subject'), 'html', 'strict', true));
            $messages_table->add_header('sender', rcube_utils::rep_specialchars_output($this->gettext('sender'), 'html', 'strict', true));
            if ($this->ama_admin === true) {
                $messages_table->add_header('recipient', rcube_utils::rep_specialchars_output($this->gettext('recipient'), 'html', 'strict', true));
            }
            $messages_table->add_header('type', rcube_utils::rep_specialchars_output($this->gettext('mailtype'), 'html', 'strict', true));
            $messages_table->add_header('level', rcube_utils::rep_specialchars_output($this->gettext('spamlevel'), 'html', 'strict', true));
        }
        // Create output : table : rows
        foreach ($quarantines as $key => $value) {
            if (!$ajax) {
                if ($key >= $pagination['start'] && $key < $pagination['stop']) {
                    $messages_table->add('release', $this->_show_radio('rel_'.$quarantines[$key]['id'], $quarantines[$key]['id'], '_rel_'.$quarantines[$key]['id']));
                    $messages_table->add('delete', $this->_show_radio('del_'.$quarantines[$key]['id'], $quarantines[$key]['id'], '_del_'.$quarantines[$key]['id']));
                    $messages_table->add('date', rcube_utils::rep_specialchars_output(date('Y-m-d H:i:s', $quarantines[$key]['received']), 'html', 'strict', true));
                    $messages_table->add('subject', $quarantines[$key]['subject'] ? '<a href="javascript: MsgView(\''.$quarantines[$key]['id'].'\',\''.urlencode($quarantines[$key]['recipient']).'\');">'.rcube_utils::rep_specialchars_output($quarantines[$key]['subject'], 'html', 'strict', true)."</a>" : '<a href="javascript: MsgView(\''.$quarantines[$key]['id'].'\',\''.urlencode($quarantines[$key]['recipient']).'\');">'.$this->gettext('no subject')."</a>");
                    $messages_table->add('sender', rcube_utils::rep_specialchars_output($quarantines[$key]['sender'], 'html', 'strict', true));
                    if ($this->ama_admin === true) {
                        $messages_table->add('recipient', rcube_utils::rep_specialchars_output($quarantines[$key]['recipient'], 'html', 'strict', true));
                    }
                    $messages_table->add('type', rcube_utils::rep_specialchars_output($this->gettext('content_decode_'.$quarantines[$key]['content']), 'html', 'strict', true));
                    $messages_table->add('level', rcube_utils::rep_specialchars_output($quarantines[$key]['level'], 'html', 'strict', true));
                }
            } else {
                $string 			= '<tr>';
                $string				.= '<td class="release">'.$this->_show_radio('rel_'.$quarantines[$key]['id'], $quarantines[$key]['id'], '_rel_'.$quarantines[$key]['id']).'</td>';
                $string				.= '<td class="delete">'.$this->_show_radio('del_'.$quarantines[$key]['id'], $quarantines[$key]['id'], '_del_'.$quarantines[$key]['id']).'</td>';
                $string				.= '<td class="date">'.rcube_utils::rep_specialchars_output(date('Y-m-d H:i:s', $quarantines[$key]['received']), 'html', 'strict', true).'</td>';
                if ($quarantines[$key]['subject']) {
                    $string				.= '<td class="subject">'.'<a href="javascript: MsgView(\''.$quarantines[$key]['id'].'\',\''.urlencode($quarantines[$key]['recipient']).'\');">'.rcube_utils::rep_specialchars_output($quarantines[$key]['subject'], 'html', 'strict', true).'</a></td>';
                } else {
                    $string				.= '<td class="subject">'.'<a href="javascript: MsgView(\''.$quarantines[$key]['id'].'\',\''.urlencode($quarantines[$key]['recipient']).'\');">'.$this->gettext('no subject').'</a></td>';
                }
                $string				.= '<td class="sender">'.rcube_utils::rep_specialchars_output($quarantines[$key]['sender'], 'html', 'strict', true).'</td>';
                if ($this->ama_admin === true) {
                    $string .= '<td class="recipient">'.rcube_utils::rep_specialchars_output($quarantines[$key]['recipient'], 'html', 'strict', true).'</td>';
                }
                $string				.= '<td class="type">'.rcube_utils::rep_specialchars_output($this->gettext('content_decode_'.$quarantines[$key]['content']), 'html', 'strict', true).'</td>';
                $string				.= '<td class="level">'.rcube_utils::rep_specialchars_output($quarantines[$key]['level'], 'html', 'strict', true).'</td>';
                $string				.= '</tr>';
                $output[]			= $string;
            }
        }
        if (!$ajax) {
            // Create output : table form
            $output_table_form = $this->rc->output->form_tag(array(
                'id' => 'quarantineform',
                'name' => 'quarantineform',
                'method' => 'post',
                'action' => './?_task=quarantine&_action=amacube-quarantine',
            ), $messages_table->show());
            // Add table container form to output
            $output .= $output_table_form;

            // Add form to client
            $this->rc->output->add_gui_object('quarantineform', 'quarantineform');
        } else {
            // Send list command
            $this->rc->output->command('amacube.messagelist', array('messages' => $output));
            // Send page commands
            if ($pagination['current'] > 1) {
                // Enable first & previous
                $this->rc->output->command('amacube.page', 'first', 'enabled');
                $this->rc->output->command('amacube.page', 'previous', 'enabled');
            } else {
                // Disable first & previous
                $this->rc->output->command('amacube.page', 'first', 'disabled');
                $this->rc->output->command('amacube.page', 'previous', 'disabled');
            }
            if ($pagination['current'] < $pagination['count']) {
                // Enable next & last
                $this->rc->output->command('amacube.page', 'next', 'enabled');
                $this->rc->output->command('amacube.page', 'last', 'enabled');
            } else {
                // Disable next & last
                $this->rc->output->command('amacube.page', 'next', 'disabled');
                $this->rc->output->command('amacube.page', 'last', 'disabled');
            }
            // Set output to nothing because client commands were used
            $output = '';
        }
        // Feedback
        $this->feedback();
        return $output;
    }

    public function quarantine_display_count()
    {
        return html::span(array('id' => 'rcmcountdisplay', 'class' => 'countdisplay quarantine-countdisplay'), $this->rc->amacube->pagecount_string);
    }

    public function quarantine_post()
    {

        // Process quarantine
        $delete = array();
        $release = array();
        foreach ($_POST as $key => $value) {
            if (preg_match('/_([dr]el)_([\w\-]+)/', $value, $matches)) {
                if ($matches[1] == 'del') {
                    array_push($delete, $matches[2]);
                } elseif ($matches[1] == 'rel') {
                    array_push($release, $matches[2]);
                }
            }
        }
        // Intersection error (should no longer happen with radio inputs but still)
        $intersect = array_intersect($delete, $release);
        if (is_array($intersect) && count($intersect) > 0) {
            $this->rc->amacube->errors[] = 'intersection_error';
            $this->rc->output->send('amacube.quarantine');
            return;
        }
        // Process released
        if (!empty($release)) {
            if ($this->amacube->quarantine->release($release)) {
                $this->rc->amacube->feedback[] = array('type' => 'confirmation', 'message' => 'success_release');
            }
        }
        // Process deleted
        if (!empty($delete)) {
            if ($this->amacube->quarantine->delete($delete)) {
                $this->rc->amacube->feedback[] = array('type' => 'confirmation', 'message' => 'success_delete');
            }
        }
    }

    public function quarantine_msgview()
    {
        $this->rc = rcmail::get_instance();
        if (($mail_id = $_GET['_mail_id']) && ($email_recip = $_GET['_recip_email'])) {
            include_once('AmavisQuarantine.php');
            $this->amacube->quarantine = new AmavisQuarantine(
                        $this->rc->config->get('amacube_db_dsn'),
                        $this->rc->config->get('amacube_amavis_host'),
                        $this->rc->config->get('amacube_amavis_port')
                    );
            $quarantine_msg_raw = $this->amacube->quarantine->get_raw_mail($mail_id, $email_recip);
            if ($quarantine_msg_raw) {
                $this->rc->config->set('prefer_html', true);
                $conf = array(
                    'include_bodies'  => true,
                    'decode_bodies'   => true,
                    'decode_headers'  => false,
                    'crlf'            => "\n",
                    'encoding'        => '8bit',
                );
                $mime = new rcube_mime_decode($conf);
                $this->quarantine_msg = $mime->decode($quarantine_msg_raw);
                // error_log("stage:action quarantine_msg parsed ".print_r($this->quarantine_msg,true));
                // set message charset as default
                if (!empty($this->quarantine_msg->headers->charset)) {
                    $this->rc->storage->set_charset($this->quarantine_msg->headers->charset);
                }
                $split_pos = strpos($quarantine_msg_raw, $conf['crlf'] . $conf['crlf']);
                if ($split_pos !== false) {
                    $this->msg_headers_raw = substr($quarantine_msg_raw, 0, $split_pos);
                }
                $this->register_handler('plugin.msgheaders', array($this, 'amacube_message_headers'));
                $this->register_handler('plugin.msgfullheaders', array($this, 'amacube_message_full_headers'));
                $this->register_handler('plugin.msgbody', array($this, 'amacube_message_body'));
                $this->register_handler('plugin.contactphoto', array($this, 'amacube_message_contactphoto'));
                $this->rc->output->add_label('deletemessage','releasemessage');
                $this->rc->output->set_env('extwin', 1);
                $this->rc->output->set_pagetitle(abbreviate_string($this->quarantine_msg->subject, 128, '...', true));
                $this->rc->output->send('amacube.msgview');
            }
        }
    }

    public function amacube_wash_html($html, $p, $cid_replaces)
    {

        $p += array('safe' => false, 'inline_html' => true);

        // charset was converted to UTF-8 in rcube_storage::get_message_part(),
        // change/add charset specification in HTML accordingly,
        // washtml cannot work without that
        $meta = '<meta http-equiv="Content-Type" content="text/html; charset='. RCUBE_CHARSET .'" />';

        // remove old meta tag and add the new one, making sure
        // that it is placed in the head (#1488093)
        $html = preg_replace('/<meta[^>]+charset=[a-z0-9-_]+[^>]*>/Ui', '', $html);
        $html = preg_replace('/(<head[^>]*>)/Ui', '\\1'.$meta, $html, -1, $rcount);
        $html = preg_replace('/(<o:[^>]*>.*<\/o:[^>]*>)/i','<!--\\1-->',$html);
        if (!$rcount) {
            $html = '<head>' . $meta . '</head>' . $html;
        }

        // clean HTML with washhtml by Frederic Motte
        $wash_opts = array(
            'show_washed'   => false,
            'allow_remote'  => $p['safe'],
            'blocked_src'   => 'program/resources/blocked.gif',
            'charset'       => RCUBE_CHARSET,
            'cid_map'       => $cid_replaces,
            'html_elements' => array('body'),
        );

        if (!$p['inline_html']) {
            $wash_opts['html_elements'] = array('html','head','title','body');
        }
        if ($p['safe']) {
            $wash_opts['html_elements'][] = 'link';
            $wash_opts['html_attribs'] = array('rel','type');
        }

        if (isset($p['html_elements']))
            $wash_opts['html_elements'] = $p['html_elements'];
        if (isset($p['html_attribs']))
            $wash_opts['html_attribs'] = $p['html_attribs'];

        // initialize HTML washer
        $washer = new rcube_washtml($wash_opts);

        if (!$p['skip_washer_form_callback']) {
            $washer->add_callback('form', "$this->amacube_washtml_callback");
        }

        // allow CSS styles, will be sanitized by amacube_washtml_callback()
        if (!$p['skip_washer_style_callback']) {
            $washer->add_callback('style', "$this->amacube_washtml_callback");
        }

        // Remove non-UTF8 characters (#1487813)
        $html = rcube_charset::clean($html);

        $html = $washer->wash($html);

        return $html;
    }

    public function amacube_washtml_callback($tagname, $attrib, $content, $washtml)
    {
        switch ($tagname) {
        case 'form':
            $out = html::div('form', $content);
            break;

        case 'style':
            // Crazy big styles may freeze the browser (#1490539)
            // remove content with more than 5k lines
            if (substr_count($content, "\n") > 5000) {
                $out = '';
                break;
            }

            // decode all escaped entities and reduce to ascii strings
            $stripped = preg_replace('/[^a-zA-Z\(:;]/', '', rcube_utils::xss_entity_decode($content));

            // now check for evil strings like expression, behavior or url()
            if (!preg_match('/expression|behavior|javascript:|import[^a]/i', $stripped)) {
                if (!$washtml->get_config('allow_remote') && stripos($stripped, 'url('))
                    $washtml->extlinks = true;
                else
                    $out = html::tag('style', array('type' => 'text/css'), $content);
                break;
            }

        default:
            $out = '';
        }

        return $out;
    }

    public function amacube_print_body($body, $part, $p = array())
    {

        $data = array('type' => $part->ctype_secondary, 'body' => $body, 'id' => $part->mime_id, 'charset' => $part->charset)
                + $p + array('safe' => false, 'plain' => false, 'inline_html' => true);

        // error_log('initial ctype_secondary: '.$part->ctype_secondary);

        // convert html to text/plain
        if ($data['plain'] && ($data['type'] == 'html' || $data['type'] == 'enriched')) {
            if ($data['type'] == 'enriched') {
                $data['body'] = rcube_enriched::to_html($data['body']);
            }

            $body = $this->rc->html2text($data['body']);
            $part->ctype_secondary = 'plain';
        }
        // text/html
        else if ($data['type'] == 'html') {
            $body = $this->amacube_wash_html($data['body'], $data, $part->replaces);
            $part->ctype_secondary = $data['type'];
        }
        // text/enriched
        else if ($data['type'] == 'enriched') {
            $body = rcube_enriched::to_html($data['body']);
            $body = $this->amacube_wash_html($body, $data, $part->replaces);
            $part->ctype_secondary = 'html';
        }
        else {
            // assert plaintext
            $body = $data['body'];
            $part->ctype_secondary = $data['type'] = 'plain';
        }

        // free some memory (hopefully)
        unset($data['body']);

        // plaintext postprocessing
        if ($part->ctype_secondary == 'plain') {
            // error_log('plain ctype_secondary: '.$part->ctype_secondary);
            $body = $this->amacube_plain_body($body, $part->ctype_parameters['format'] == 'flowed');
        }

        // allow post-processing of the message body
        $data = array('type' => $part->ctype_secondary, 'body' => $body, 'id' => $part->mime_id) + $data;

        return $data['body'];
    }

    public function amacube_plain_body($body, $flowed = false)
    {
        // error_log('plain forced ??? body: '.$body);
        $options   = array('flowed' => $flowed, 'wrap' => !$flowed, 'replacer' => 'rcmail_string_replacer');
        $text2html = new rcube_text2html($body, false, $options);
        $body      = $text2html->get_html();

        return $body;
    }


    public function amacube_localized_priority($value)
    {

        $labels_map = array(
            '1' => 'highest',
            '2' => 'high',
            '3' => 'normal',
            '4' => 'low',
            '5' => 'lowest',
        );

        if ($value && $labels_map[$value]) {
            return $this->rc->gettext($labels_map[$value]);
        }

        return '';
    }

    public function amacube_html4inline($body, $container_id, $body_class='', &$attributes=null, $allow_remote=false)
    {
        $last_style_pos = 0;
        $cont_id        = $container_id . ($body_class ? ' div.'.$body_class : '');

        // find STYLE tags
        while (($pos = stripos($body, '<style', $last_style_pos)) && ($pos2 = stripos($body, '</style>', $pos))) {
            $pos = strpos($body, '>', $pos) + 1;
            $len = $pos2 - $pos;

            // replace all css definitions with #container [def]
            $styles = substr($body, $pos, $len);
            $styles = rcube_utils::mod_css_styles($styles, $cont_id, $allow_remote);

            $body = substr_replace($body, $styles, $pos, $len);
            $last_style_pos = $pos2 + strlen($styles) - $len;
        }

        // modify HTML links to open a new window if clicked
        $GLOBALS['amacube_html_container_id'] = $container_id;
        $body = preg_replace_callback('/<(a|link|area)\s+([^>]+)>/Ui', 'self::amacube_alter_html_link', $body);
        unset($GLOBALS['amacube_html_container_id']);

        $body = preg_replace(array(
                // add comments arround html and other tags
                '/(<!DOCTYPE[^>]*>)/i',
                '/(<\?xml[^>]*>)/i',
                '/(<\/?xml[^>]*>)/i',
                '/(<o:[^>]*>.*<\/o:[^>]*>)/i',
                '/(<\/?html[^>]*>)/i',
                '/(<\/?head[^>]*>)/i',
                '/(<title[^>]*>.*<\/title>)/Ui',
                '/(<\/?meta[^>]*>)/i',
                // quote <? of php and xml files that are specified as text/html
                '/<\?/',
                '/\?>/',
                // replace <body> with <div>
                '/<body([^>]*)>/i',
                '/<\/body>/i',
            ),
            array(
                '<!--\\1-->',
                '<!--\\1-->',
                '<!--\\1-->',
                '<!--\\1-->',
                '<!--\\1-->',
                '<!--\\1-->',
                '<!--\\1-->',
                '<!--\\1-->',
                '&lt;?',
                '?&gt;',
                '<div class="' . $body_class . '"\\1>',
                '</div>',
            ),
            $body);

        $attributes = array();

        // Handle body attributes that doesn't play nicely with div elements
        $regexp = '/<div class="' . preg_quote($body_class, '/') . '"([^>]*)/';
        if (preg_match($regexp, $body, $m)) {
            $attrs = $m[0];

            // Get bgcolor, we'll set it as background-color of the message container
            if ($m[1] && preg_match('/bgcolor=["\']*([a-z0-9#]+)["\']*/i', $attrs, $mb)) {
                $attributes['background-color'] = $mb[1];
                $attrs = preg_replace('/bgcolor=["\']*[a-z0-9#]+["\']*/i', '', $attrs);
            }

            // Get background, we'll set it as background-image of the message container
            if ($m[1] && preg_match('/background=["\']*([^"\'>\s]+)["\']*/', $attrs, $mb)) {
                $attributes['background-image'] = 'url('.$mb[1].')';
                $attrs = preg_replace('/background=["\']*([^"\'>\s]+)["\']*/', '', $attrs);
            }

            if (!empty($attributes)) {
                $body = preg_replace($regexp, rtrim($attrs), $body, 1);
            }

            // handle body styles related to background image
            if ($attributes['background-image']) {
                // get body style
                if (preg_match('/#'.preg_quote($cont_id, '/').'\s+\{([^}]+)}/i', $body, $m)) {
                    // get background related style
                    $regexp = '/(background-position|background-repeat)\s*:\s*([^;]+);/i';
                    if (preg_match_all($regexp, $m[1], $ma, PREG_SET_ORDER)) {
                        foreach ($ma as $style) {
                            $attributes[$style[1]] = $style[2];
                        }
                    }
                }
            }
        }
        // make sure there's 'rcmBody' div, we need it for proper css modification
        // its name is hardcoded in amacube_message_body() also
        else {
            $body = '<div class="' . $body_class . '">' . $body . '</div>';
        }

        return $body;
    }

    public function amacube_alter_html_link($matches)
    {

        $tag    = strtolower($matches[1]);
        $attrib = html::parse_attrib_string($matches[2]);
        $end    = '>';

        // Remove non-printable characters in URL (#1487805)
        if ($attrib['href'])
            $attrib['href'] = preg_replace('/[\x00-\x1F]/', '', $attrib['href']);

        if ($tag == 'link' && preg_match('/^https?:\/\//i', $attrib['href'])) {
            $tempurl = 'tmp-' . md5($attrib['href']) . '.css';
            $_SESSION['modcssurls'][$tempurl] = $attrib['href'];
            $attrib['href'] = $this->rc->url(array('task' => 'utils', 'action' => 'modcss', 'u' => $tempurl, 'c' => $GLOBALS['amacube_html_container_id']));
            $end = ' />';
        }
        else if (preg_match('/^mailto:(.+)/i', $attrib['href'], $mailto)) {
            list($mailto, $url) = explode('?', html_entity_decode($mailto[1], ENT_QUOTES, 'UTF-8'), 2);

            $url       = urldecode($url);
            $mailto    = urldecode($mailto);
            $addresses = rcube_mime::decode_address_list($mailto, null, true);
            $mailto    = array();

            // do sanity checks on recipients
            foreach ($addresses as $idx => $addr) {
                if (rcube_utils::check_email($addr['mailto'], false)) {
                    $addresses[$idx] = $addr['mailto'];
                    $mailto[]        = $addr['string'];
                }
                else {
                    unset($addresses[$idx]);
                }
            }

            if (!empty($addresses)) {
                $attrib['href']    = 'mailto:' . implode(',', $addresses);
                $attrib['onclick'] = sprintf(
                    "return %s.command('compose','%s',this)",
                    rcmail_output::JS_OBJECT_NAME,
                    rcube::JQ(implode(',', $mailto) . ($url ? "?$url" : '')));
            }
            else {
                $attrib['href']    = '#NOP';
                $attrib['onclick'] = '';
            }
        }
        else if (empty($attrib['href']) && !$attrib['name']) {
            $attrib['href']    = './#NOP';
            $attrib['onclick'] = 'return false';
        }
        else if (!empty($attrib['href']) && $attrib['href'][0] != '#') {
            $attrib['target'] = '_blank';
        }

        // Better security by adding rel="noreferrer" (#1484686)
        if (($tag == 'a' || $tag == 'area') && $attrib['href'] && $attrib['href'][0] != '#') {
            $showlink = $attrib['href'];
            $attrib['href']    = './#NOP';
            $attrib['onclick'] = 'return false';
            $tag = "span> Link to ".$showlink." disarmed</span><".$tag;
            $attrib['rel'] = 'noreferrer';
        }

        // allowed attributes for a|link|area tags
        $allow = array('href','name','target','onclick','id','class','style','title',
            'rel','type','media','alt','coords','nohref','hreflang','shape');

        return "<$tag" . html::attrib_string($attrib, $allow) . $end;
    }

    public function amacube_address_string($input, $max=null, $linked=false, $addicon=null, $default_charset=null, $title=null)
    {

        $a_parts = rcube_mime::decode_address_list($input, null, true, $default_charset);

        if (!sizeof($a_parts)) {
            return $input;
        }

        $c   = count($a_parts);
        $j   = 0;
        $out = '';
        $allvalues  = array();
        $show_email = $this->rc->config->get('message_show_email');

        foreach ($a_parts as $part) {
            $j++;

            $name   = $part['name'];
            $mailto = $part['mailto'];
            $string = $part['string'];
            $valid  = rcube_utils::check_email($mailto, false);

            // phishing email prevention (#1488981), e.g. "valid@email.addr <phishing@email.addr>"
            if (!$show_email && $valid && $name && $name != $mailto && strpos($name, '@')) {
                $name = '';
            }

            // IDNA ASCII to Unicode
            if ($name == $mailto)
                $name = rcube_utils::idn_to_utf8($name);
            if ($string == $mailto)
                $string = rcube_utils::idn_to_utf8($string);
            $mailto = rcube_utils::idn_to_utf8($mailto);

            if ($valid) {
                if ($linked) {
                    $attrs = array(
                        'href'    => 'mailto:' . $mailto,
                        'class'   => 'rcmContactAddress',
                        'onclick' => sprintf("return %s.command('compose','%s',this)",
                            rcmail_output::JS_OBJECT_NAME, rcube::JQ(format_email_recipient($mailto, $name))),
                    );

                    if ($show_email && $name && $mailto) {
                        $content = rcube::Q($name ? sprintf('%s <%s>', $name, $mailto) : $mailto);
                    }
                    else {
                        $content = rcube::Q($name ? $name : $mailto);
                        $attrs['title'] = $mailto;
                    }

                    $address = html::a($attrs, $content);
                }
                else {
                    $address = html::span(array('title' => $mailto, 'class' => "rcmContactAddress"),
                        rcube::Q($name ? $name : $mailto));
                }

            }
            else {
                $address = '';
                if ($name)
                    $address .= rcube::Q($name);
                if ($mailto)
                    $address = trim($address . ' ' . rcube::Q($name ? sprintf('<%s>', $mailto) : $mailto));
            }

            $address = html::span('adr', $address);
            $allvalues[] = $address;

            if (!$moreadrs)
                $out .= ($out ? ', ' : '') . $address;

            if ($max && $j == $max && $c > $j) {
                if ($linked) {
                    $moreadrs = $c - $j;
                }
                else {
                    $out .= '...';
                    break;
                }
            }
        }

        if ($moreadrs) {
            $out .= ' ' . html::a(array(
                    'href'    => '#more',
                    'class'   => 'morelink',
                    'onclick' => sprintf("return %s.show_popup_dialog('%s','%s')",
                        rcmail_output::JS_OBJECT_NAME,
                        rcube::JQ(join(', ', $allvalues)),
                        rcube::JQ($title))
                ),
                rcube::Q($this->rc->gettext(array('name' => 'andnmore', 'vars' => array('nr' => $moreadrs)))));
        }

        return $out;
    }

    public function amacube_message_contactphoto($attrib)
    {
        $skin_path = $this->local_skin_path();
        if (is_file(realpath(slashify($this->home) . $skin_path."/images/contactpic_48px.png"))) {
            $photo_img = $this->url($skin_path."/images/contactpic_48px.png");
            return html::img(array('src' => $photo_img, 'alt' => $this->rc->gettext('contactphoto')) + $attrib);
        } else {
            return '';
        }
    }

    public function amacube_message_headers($attrib, $headers=null)
    {
        static $sa_attrib;

        // keep header table attrib
        if (is_array($attrib) && !$sa_attrib && !$attrib['valueof'])
            $sa_attrib = $attrib;
        else if (!is_array($attrib) && is_array($sa_attrib))
            $attrib = $sa_attrib;

        if (!isset($this->quarantine_msg)) {
            return false;
        }

        // get associative array of headers object
        if (!$headers) {
            $headers_obj = (object) $this->quarantine_msg->headers;
            $headers     = get_object_vars($headers_obj);
//            $headers     = get_object_vars($this->quarantine_msg->headers);
        }
        else if (is_object($headers)) {
            $headers_obj = $headers;
            $headers     = get_object_vars($headers_obj);
        }
        else {
            $headers_obj = rcube_message_header::from_array($headers);
        }

        // show these headers
        $standard_headers = array('subject', 'from', 'sender', 'to', 'cc', 'bcc', 'replyto',
            'mail-reply-to', 'mail-followup-to', 'date', 'priority');
        $exclude_headers = $attrib['exclude'] ? explode(',', $attrib['exclude']) : array();
        $this->rc->output_headers  = array();

        foreach ($standard_headers as $hkey) {
            $ishtml = false;

            if ($headers[$hkey])
                $value = $headers[$hkey];
            else if ($headers['others'][$hkey])
                $value = $headers['others'][$hkey];
            else if (!$attrib['valueof'])
                continue;

            if (in_array($hkey, $exclude_headers))
                continue;

            $header_title = $this->rc->gettext(preg_replace('/(^mail-|-)/', '', $hkey));

            if ($hkey == 'date') {
                    $header_value = $this->rc->format_date($value);
            }
            else if ($hkey == 'priority') {
                if ($value) {
                    $header_value = html::span('prio' . $value, $this->amacube_localized_priority($value));
                }
                else
                    continue;
            }
            else if ($hkey == 'replyto') {
                if ($headers['replyto'] != $headers['from']) {
                    $header_value = $this->amacube_address_string($value, $attrib['max'], true,
                        $attrib['addicon'], $headers['charset'], $header_title);
                    $ishtml = true;
                }
                else
                    continue;
            }
            else if ($hkey == 'mail-reply-to') {
                if ($headers['mail-replyto'] != $headers['reply-to']
                    && $headers['reply-to'] != $headers['from']
                ) {
                    $header_value = $this->amacube_address_string($value, $attrib['max'], true,
                        $attrib['addicon'], $headers['charset'], $header_title);
                    $ishtml = true;
                }
                else
                    continue;
            }
            else if ($hkey == 'sender') {
                if ($headers['sender'] != $headers['from']) {
                    $header_value = $this->amacube_address_string($value, $attrib['max'], true,
                        $attrib['addicon'], $headers['charset'], $header_title);
                    $ishtml = true;
                }
                else
                    continue;
            }
            else if ($hkey == 'mail-followup-to') {
                $header_value = $this->amacube_address_string($value, $attrib['max'], true,
                    $attrib['addicon'], $headers['charset'], $header_title);
                $ishtml = true;
            }
            else if (in_array($hkey, array('from', 'to', 'cc', 'bcc'))) {
                $header_value = $this->amacube_address_string($value, $attrib['max'], true,
                    $attrib['addicon'], $headers['charset'], $header_title);
                $ishtml = true;
            }
            else if ($hkey == 'subject' && empty($value))
                $header_value = $this->rc->gettext('nosubject');
            else {
                $value        = is_array($value) ? implode(' ', $value) : $value;
                $header_value = trim(rcube_mime::decode_header($value, $headers['charset']));
            }

            $this->rc->output_headers[$hkey] = array(
                'title' => $header_title,
                'value' => $header_value,
                'raw'   => $value,
                'html'  => $ishtml,
            );
        }

        $plugin = $this->rc->plugins->exec_hook('message_headers_output', array(
            'output'  => $this->rc->output_headers,
            'headers' => $headers_obj,
            'exclude' => $exclude_headers, // readonly
            'folder'  => $this->quarantine_msg->folder, // readonly
            'uid'     => $this->quarantine_msg->uid,    // readonly
        ));

        // single header value is requested
        if (!empty($attrib['valueof'])) {
            return rcube::Q($plugin['output'][$attrib['valueof']]['value'], ($attrib['valueof'] == 'subject' ? 'strict' : 'show'));
        }

        // compose html table
        $table = new html_table(array('cols' => 2));

        foreach ($plugin['output'] as $hkey => $row) {
            $val = $row['html'] ? $row['value'] : rcube::Q($row['value'], ($hkey == 'subject' ? 'strict' : 'show'));
            $table->add(array('class' => 'header-title'), rcube::Q($row['title']));
            $table->add(array('class' => 'header '.$hkey), $val);
        }

        return $table->show($attrib);
    }

    public function amacube_message_full_headers($attrib)
    {

        $headers_textarea = new html_textarea();
        $html = html::div(array(
                'class'   => "more-headers show-headers",
                'onclick' => 'javascript:(function(){var messagecontent = document.getElementById("messagecontent");var msghdrsrc = document.getElementById("all-headers");if(msghdrsrc.style.display==="none"){msghdrsrc.style.display = "block";messagecontent.style.top = "336px";}else{msghdrsrc.style.display = "none";messagecontent.style.top="156px";}})();',
                'title'   => $this->rc->gettext('togglefullheaders')
            ), '');
        $html .= html::div(array('id' => "all-headers", 'class' => "all", 'style' => 'display:none'), html::div(array('id' => 'headers-source'), $headers_textarea->show($this->msg_headers_raw,array('readonly' => true, 'style' => 'width: 98%;height: 150px;'))));

//        $this->rc->output->add_gui_object('all_headers_row', 'all-headers');

//        $this->rc->output->add_gui_object('all_headers_box', 'headers-source');

        return html::div($attrib, $html);
    }

    public function amacube_message_body($attrib)
    {
        if (!is_array($this->quarantine_msg->parts) && empty($this->quarantine_msg->body)) {
            return '';
        }

        if (!$attrib['id'])
            $attrib['id'] = 'rcmailMsgBody';

        $safe_mode = false;
        $out       = '';
        $part_no   = 0;

        $header_attrib = array();
        foreach ($attrib as $attr => $value) {
            if (preg_match('/^headertable([a-z]+)$/i', $attr, $regs)) {
                $header_attrib[$regs[1]] = $value;
            }
        }

        if (!empty($this->quarantine_msg->parts)) {
            foreach ($this->quarantine_msg->parts as $part) {
                if ($part->type == 'headers') {
                    $out .= html::div('message-partheaders', $this->amacube_message_headers(sizeof($header_attrib) ? $header_attrib : null, $part->headers));
                }
                else if (($part->ctype_primary == 'text') && in_array($part->ctype_secondary,array('plain','html')) && (!empty($part->body))) {
                    // unsupported (e.g. encrypted)
                    if ($part->realtype) {
                        continue;
                    }
                    else if (!$part->size) {
                        continue;
                    }
                    if ($part->ctype_secondary == 'html') {
                        $body = $part->body;
                        if (!empty($part->charset) && (strtoupper($part->charset) != 'UTF-8')) {
                            $body = rcube_charset::convert($body, strtoupper($part->charset));
                        }
                        $body = $this->amacube_print_body($body, $part, array('safe' => $safe_mode, 'plain' => !$this->rc->config->get('prefer_html')));
                        $container_id = 'message-htmlpart' . (++$part_no);
                        $body         = $this->amacube_html4inline($body, $container_id, 'rcmBody', $attrs, $safe_mode);
                        $div_attr     = array('class' => 'message-htmlpart', 'id' => $container_id);
                        $style        = array();
                        // error_log("stage:handler msgbody after inline body ".print_r($body,true));

                        if (!empty($attrs)) {
                            foreach ($attrs as $a_idx => $a_val)
                                $style[] = $a_idx . ': ' . $a_val;
                            if (!empty($style))
                                $div_attr['style'] = implode('; ', $style);
                        }

                        $out .= html::div($div_attr, $body);
                    }
                    else
                        $out .= html::div('message-part', $body);
                }
                else if ($part->ctype_primary == 'multipart') {
                    foreach ($part->parts as $multipart) {
                        if (($multipart->ctype_primary == 'text') && in_array($multipart->ctype_secondary,array('plain','html')) && (!empty($multipart->body))) {
                            // unsupported (e.g. encrypted)
                            if ($multipart->realtype) {
                                continue;
                            }
                            else if (!$multipart->size) {
                                continue;
                            }
                            if ($multipart->ctype_secondary == 'html') {
                                $body = $multipart->body;
                                if (!empty($multipart->charset) && (strtoupper($multipart->charset) != 'UTF-8')) {
                                    $body = rcube_charset::convert($body, strtoupper($multipart->charset));
                                }
                                $body = $this->amacube_print_body($body, $multipart, array('safe' => $safe_mode, 'plain' => !$this->rc->config->get('prefer_html')));
                                $container_id = 'message-htmlpart' . (++$part_no);
                                $body         = $this->amacube_html4inline($body, $container_id, 'rcmBody', $attrs, $safe_mode);
                                $div_attr     = array('class' => 'message-htmlpart', 'id' => $container_id);
                                $style        = array();
                                // error_log("stage:handler msgbody after inline body ".print_r($body,true));

                                if (!empty($attrs)) {
                                    foreach ($attrs as $a_idx => $a_val)
                                        $style[] = $a_idx . ': ' . $a_val;
                                    if (!empty($style))
                                        $div_attr['style'] = implode('; ', $style);
                                }

                                $out .= html::div($div_attr, $body);
                            }
                            else
                                $out .= html::div('message-part', $body);
                        }
                    }
                }
            }
        }
        else if (($this->quarantine_msg->ctype_secondary == 'html') && (!empty($this->quarantine_msg->body))) {
                        $body = $this->quarantine_msg->body;
                        if (!empty($this->quarantine_msg->charset) && (strtoupper($this->quarantine_msg->charset) != 'UTF-8')) {
                            $body = rcube_charset::convert($body, strtoupper($this->quarantine_msg->charset));
                        }
                        $body = $this->amacube_print_body($body, $this->quarantine_msg, array('safe' => $safe_mode, 'plain' => !$this->rc->config->get('prefer_html')));
                        $container_id = 'message-htmlpart' . (++$part_no);
                        $body         = $this->amacube_html4inline($body, $container_id, 'rcmBody', $attrs, $safe_mode);
                        $div_attr     = array('class' => 'message-htmlpart', 'id' => $container_id);
                        $style        = array();
                        // error_log("stage:handler msgbody after inline body ".print_r($body,true));

                        if (!empty($attrs)) {
                            foreach ($attrs as $a_idx => $a_val)
                                $style[] = $a_idx . ': ' . $a_val;
                            if (!empty($style))
                                $div_attr['style'] = implode('; ', $style);
                        }

                        $out .= html::div($div_attr, $body);
                    }
        else {
            // error_log('no parts parsed '."\n".print_r($this->quarantine_msg,true)."\n");
            $out .= html::div('message-part', $this->amacube_plain_body($this->quarantine_msg->body));
        }

        // error_log("stage:handler msgbody out ".print_r($out,true));
        return html::div($attrib, $out);
    }

    public function feedback()
    {
        // Send first error or feedbacks to client
        if (!empty($this->rc->amacube->errors)) {
            $this->rc->output->command('display_message', rcube_utils::rep_specialchars_output($this->gettext($this->rc->amacube->errors[0]), 'html', 'strict', true), 'error');
        } elseif (!empty($this->rc->amacube->feedback)) {
            foreach ($this->rc->amacube->feedback as $feed) {
                if (!empty($feed)) {
                    $this->rc->output->command('display_message', rcube_utils::rep_specialchars_output($this->gettext($feed['message']), 'html', 'strict', true), $feed['type']);
                }
            }
        }
    }

    // CONVENIENCE METHODS
    // This bloody html_checkbox class will always return checkboxes that are "checked"
    // I did not figure out how to prevent that $$*@@!!
    // so I used html::tag instead...
    public function _show_checkbox($id, $checked = false)
    {
        $attr_array = array('name' => $id,'id' => $id);
        if ($checked) {
            $attr_array['checked'] = 'checked';
        }
        //$box = new html_checkbox($attr_array);
        $attr_array['type'] = 'checkbox';
        $box = html::tag('input', $attr_array);
        return $box;
    }
    public function _show_radio($id, $name, $value, $checked = false)
    {
        $attr_array = array('name' => $name,'id' => $id);
        if ($checked) {
            $attr_array['checked'] = 'checked';
        }
        //$box = new html_checkbox($attr_array);
        $attr_array['type'] = 'radio';
        $attr_array['value'] = $value;
        $box = html::tag('input', $attr_array);
        return $box;
    }
    public function _show_inputfield($id, $value)
    {
        $input = new html_inputfield(array(
                'name' => $id,
                'id' => $id,
                'value' => $value,
                'size'  =>  10
        ));
        return $input->show();
    }
}
