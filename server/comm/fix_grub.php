<?php

	$sb_status = sb_status_fetch();

	$status = 0;
	$reason = 'Off';
	$dev    = '';

	if ( $sb_status['status'] == 'restore' && $sb_status['stage'] == 'fixes' ) {

		if ( strpos( $sb_status['setting6'], 'fixgrub' ) !== false ) {

			if ($sb_status['setting3'] == '-XEN-') {
				$filepath     = $settings['mount_migrate']  . '/';
			} else {
				$filepath     = $settings['mount_backups'] . '/' . $sb_status['setting1'] . '/' . $sb_status['setting2'] . '/' . $sb_status['setting3'] . '/';
			}
			$disktypeget    = sb_check_disks();
			$numberofimages = count( $disktypeget['avaliabledisks'] );
			$processdisks   = sb_disk_array_fetch( $filepath );

			$dev = $disktypeget['newbootdisk'];

			//set files to be grabbed by crontab job
			if ( $sb_status['step'] == 0 ) {
				if ( $cronsfile = fopen( $projectpath . 'crons/fixgrub.dat', "w" ) ) {
					fwrite( $cronsfile, '1' );
					fclose( $cronsfile );
				}
				if ( $cronsfile = fopen( $projectpath . 'crons/fixgrubtarget.dat', "w" ) ) {
					fwrite( $cronsfile, $dev );
					fclose( $cronsfile );
				}
				sb_log('Fix Grub - DEVICE: ' . $dev);

				sb_status_set( 'restore', 'fixes', 1 );
			}

			if ( file_exists( $projectpath . 'crons/fixgrub.dat' ) ) {
				$cronsetting = file_get_contents( $projectpath . 'crons/fixgrub.dat' );
				if ( $cronsetting == 1 ) {
					$status = 1;
					$reason = 'Waiting for cron job';
				} else if ( $cronsetting == 2 ) {
					$status = 2;
					$reason = 'Completed';
					if ( $cronsfile = fopen( $projectpath . 'crons/fixgrub.dat', "w" ) ) {
						fwrite( $cronsfile, 0 );
						fclose( $cronsfile );
					}
					$sb_status['setting6'] = str_replace( 'fixgrub', '', $sb_status['setting6'] );
					sb_status_set( 'restore', 'fixes', 0, '', '', '', '', '', $sb_status['setting6'] );

				} else if ( $cronsetting == 0 ) {
					$status = 0;
					$reason = 'Off';

				}
			}



		} else {

			$status = 2;
			$reason = 'Completed';
		}
	}

	sleep( 2 );

	$jsonarray = array(
		"status" => $status,
		"reason" => $reason,
	);

	sb_log('Fix Grub - ' . $reason);

	echo json_encode( $jsonarray );

