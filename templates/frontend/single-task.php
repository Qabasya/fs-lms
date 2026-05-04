<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post_id = get_the_ID();
$post_type = get_post_type( $post_id );
$subject_key = str_replace( '_tasks', '', $post_type );

$post_id = get_the_ID();

$container           = new \Inc\Core\Container();
$task_page_callbacks = $container->get( \Inc\Callbacks\TaskPageCallbacks::class );
$task_data           = $task_page_callbacks->getTaskData( $post_id );

echo '<pre>';
print_r( $task_data );
echo '</pre>';
