<?php

$mbox = null;
$id = null;
$mail = @imap_fetchheader($mbox, $id, FT_UID) . @imap_body($mbox, $id, FT_PEEK | FT_UID);

printf("MAIL <%s>\n", $mail);
printf("EMPTY <%b>\n", empty($mail));