<?php
require_once FS_LMS_PATH . 'templates/admin/ui_renderers.php';
?>

<div id="tab-2" class="tab-pane active">
    <div class="header-row">
        <h1 class="wp-heading-inline">Настройка авторизации</h1>

    </div>
    <form method="post" action="options.php">
        <?php
        settings_fields( 'fs_lms_auth_group' );
        $options = get_option( 'fs_lms_auth_settings', [] );
        ?>

        <table class="form-table" role="presentation">
            <tbody>
            <!-- Google Section -->
            <tr>
                <th scope="row">Google Auth</th>
                <td>
                    <?php render_fs_toggle(
                            'fs_lms_auth_settings[google_enabled]',
                            !empty($options['google_enabled']),
                            [
                                    'class' => 'js-provider-toggle',
                                    'id'    => 'google_toggle',
                                    'args'  => [ 'data-provider' => 'google' ] // Добавили
                            ]
                    ); ?>
                </td>
            </tr>
            <tr class="auth-fields-google <?php echo empty($options['google_enabled']) ? 'hidden' : ''; ?>">
                <th scope="row"><label for="google_id">Google Client ID</label></th>
                <td>
                    <input name="fs_lms_auth_settings[google_id]" type="text" id="google_id"
                           value="<?php echo esc_attr( $options['google_id'] ?? '' ); ?>" class="regular-text">
                </td>
            </tr>
            <tr class="auth-fields-google <?php echo empty($options['google_enabled']) ? 'hidden' : ''; ?>">
                <th scope="row"><label for="google_secret">Google Client Secret</label></th>
                <td>
                    <input name="fs_lms_auth_settings[google_secret]" type="password" id="google_secret"
                           value="<?php echo esc_attr( $options['google_secret'] ?? '' ); ?>" class="regular-text">
                </td>
            </tr>

            <!-- VK Section -->
            <tr>
                <th scope="row">ВКонтакте</th>
                <td>
                    <?php render_fs_toggle(
                            'fs_lms_auth_settings[vk_enabled]',
                            !empty($options['vk_enabled']),
                            [
                                    'class' => 'js-provider-toggle',
                                    'id'    => 'vk_toggle',
                                    'args'  => [ 'data-provider' => 'vk' ] // Добавили
                            ]
                    ); ?>
                </td>
            </tr>
            <tr class="auth-fields-vk <?php echo empty($options['vk_enabled']) ? 'hidden' : ''; ?>">
                <th scope="row"><label for="vk_id">VK App ID</label></th>
                <td>
                    <input name="fs_lms_auth_settings[vk_id]" type="text" id="vk_id"
                           value="<?php echo esc_attr( $options['vk_id'] ?? '' ); ?>" class="regular-text">
                </td>
            </tr>
            <tr class="auth-fields-vk <?php echo empty($options['vk_enabled']) ? 'hidden' : ''; ?>">
                <th scope="row"><label for="vk_secret">VK App Secret</label></th>
                <td>
                    <input name="fs_lms_auth_settings[vk_secret]" type="password" id="vk_secret"
                           value="<?php echo esc_attr( $options['vk_secret'] ?? '' ); ?>" class="regular-text">
                </td>
            </tr>

            <!-- GitHub Section -->
            <tr>
                <th scope="row">GitHub</th>
                <td>
                    <?php render_fs_toggle(
                            'fs_lms_auth_settings[github_enabled]',
                            !empty($options['github_enabled']),
                            [
                                    'class' => 'js-provider-toggle',
                                    'id'    => 'github_toggle',
                                    'args'  => [ 'data-provider' => 'github' ]
                            ]
                    ); ?>
                </td>
            </tr>
            <tr class="auth-fields-github <?php echo empty($options['github_enabled']) ? 'hidden' : ''; ?>">
                <th scope="row"><label for="github_id">GitHub Client ID</label></th>
                <td>
                    <input name="fs_lms_auth_settings[github_id]" type="text" id="github_id"
                           value="<?php echo esc_attr( $options['github_id'] ?? '' ); ?>" class="regular-text">
                </td>
            </tr>
            <tr class="auth-fields-github <?php echo empty($options['github_enabled']) ? 'hidden' : ''; ?>">
                <th scope="row"><label for="github_secret">GitHub Client Secret</label></th>
                <td>
                    <input name="fs_lms_auth_settings[github_secret]" type="password" id="github_secret"
                           value="<?php echo esc_attr( $options['github_secret'] ?? '' ); ?>" class="regular-text">
                </td>
            </tr>
            </tbody>
        </table>

        <?php submit_button(); ?>
    </form>
</div>