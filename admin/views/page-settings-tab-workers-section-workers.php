<p class="description"><?php _e( 'Hier solltest Du Deine Dienstanbieter definieren, für die Dein Client Termine vereinbaren wird. <b>Es muss mindestens ein Service definiert sein.</b> Kapazität ist die Anzahl der Clienten, die den Service gleichzeitig nutzen können. Gib 0 für kein bestimmtes Limit ein (Beschränkt auf die Anzahl der Dienstanbieter oder auf 1, wenn für diesen Dienst kein Dienstanbieter definiert ist). Der Preis ist nur erforderlich, wenn Du die Zahlung anforderst, um Termine anzunehmen. Du kannst eine Beschreibungsseite für den von Dir bereitgestellten Dienst definieren.', 'appointments' ) ?></p>
<form method="post" action="">
    <input type="hidden" name="app-current-tab" value="workers" />
<?php
global $appointments_workers_list;
$appointments_workers_list->prepare_items();
$appointments_workers_list->display();
?>
</form>
