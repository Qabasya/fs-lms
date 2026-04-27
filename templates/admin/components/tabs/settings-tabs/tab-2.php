<?php
$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'tab-1';
?>

<div id="tab-2" class="tab-pane <?php echo $active_tab == 'tab-2' ? 'active' : ''; ?>">
	<h3>Вкладка 2</h3>
</div>