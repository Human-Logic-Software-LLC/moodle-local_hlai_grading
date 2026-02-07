<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Human Logic AI Grading settings page.
 * Developed and maintained by Human Logic Software LLC.
 */

if ($hassiteconfig) {
    $settings = new admin_settingpage('local_hlai_grading', get_string('pluginname', 'local_hlai_grading'));

    $settings->add(new admin_setting_heading(
        'local_hlai_grading/branding',
        '',
        get_string('branding', 'local_hlai_grading')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_hlai_grading/enable',
        get_string('setting_enable', 'local_hlai_grading'),
        get_string('setting_enable_desc', 'local_hlai_grading'),
        1
    ));

    $gatewayurlsetting = new admin_setting_configtext(
        'local_hlai_grading/gatewayurl',
        get_string('setting_gatewayurl', 'local_hlai_grading'),
        get_string('setting_gatewayurl_desc', 'local_hlai_grading'),
        'https://ai.human-logic.com',
        PARAM_URL
    );
    $gatewayurlsetting->set_locked_flag_options(
        admin_setting_flag::ENABLED,
        true
    );
    $settings->add($gatewayurlsetting);

    $settings->add(new admin_setting_configpasswordunmask(
        'local_hlai_grading/gatewaykey',
        get_string('setting_gatewaykey', 'local_hlai_grading'),
        get_string('setting_gatewaykey_desc', 'local_hlai_grading'),
        ''
    ));

    $qualityoptions = [
        'fast' => get_string('assignsettingsquality_fast', 'local_hlai_grading'),
        'balanced' => get_string('assignsettingsquality_balanced', 'local_hlai_grading'),
        'best' => get_string('assignsettingsquality_best', 'local_hlai_grading'),
    ];
    $settings->add(new admin_setting_configselect(
        'local_hlai_grading/defaultquality',
        get_string('setting_quality', 'local_hlai_grading'),
        get_string('setting_quality_desc', 'local_hlai_grading'),
        'balanced',
        $qualityoptions
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_hlai_grading/pushgrades',
        get_string('setting_pushgrades', 'local_hlai_grading'),
        get_string('setting_pushgrades_desc', 'local_hlai_grading'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_hlai_grading/pushfeedback',
        get_string('setting_pushfeedback', 'local_hlai_grading'),
        get_string('setting_pushfeedback_desc', 'local_hlai_grading'),
        1
    ));

    $settings->add(new admin_setting_configtext(
        'local_hlai_grading/dataretentionmonths',
        get_string('setting_dataretention', 'local_hlai_grading'),
        get_string('setting_dataretention_desc', 'local_hlai_grading'),
        24,
        PARAM_INT
    ));

    $settings->add(new admin_setting_configtext(
        'local_hlai_grading/antiwordpath',
        get_string('setting_antiwordpath', 'local_hlai_grading'),
        get_string('setting_antiwordpath_desc', 'local_hlai_grading'),
        '',
        PARAM_RAW_TRIMMED
    ));

    $ADMIN->add('localplugins', $settings);

    $ADMIN->add('localplugins', new admin_externalpage(
        'local_hlai_grading_runqueue',
        get_string('runqueuepage', 'local_hlai_grading'),
        new moodle_url('/local/hlai_grading/run_queue.php'),
        'moodle/site:config'
    ));
}
