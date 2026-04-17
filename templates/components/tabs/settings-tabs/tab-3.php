<?php
$active_tab = isset( $_GET['tab'] ) ? sanitize_text_field( $_GET['tab'] ) : 'tab-1';
?>

<div id="tab-3" class="tab-pane <?php echo $active_tab == 'tab-3' ? 'active' : ''; ?>">
    <h3>Вкладка 3</h3>
</div>