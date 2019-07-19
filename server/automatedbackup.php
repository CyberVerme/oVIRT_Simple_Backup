<?php

	set_time_limit( 6 * 60 * 60 );//6 hours

	$projectpath = '/var/www/html/';

	require( $projectpath . 'allowed_ips.php' );
	require( $projectpath . 'functions.php' );
	require( $projectpath . 'config.php' );
	require( $projectpath . 'reg.php' );
	require( $projectpath . 'tz.php' );

	//get schedule(s)
	$configdata = null;
	$files      = null;
	exec( 'ls ' . $projectpath . '.automated_backups_schedule_*', $files );
	$matchingschedule = 0;
	$schedulename     = '';

	//check to see when last backup was run and do not allow a "double run"
	if ( file_exists( $vmbackupinprocessfile . '_lastrun' ) ) {
		if ( time() - filemtime( $vmbackupinprocessfile . '_lastrun' ) > 900 ) {
			$lastrunokay = 1;//15 minutes old
			exec( 'rm ' . $vmbackupinprocessfile . '_lastrun' );
		} else {
			$lastrunokay = 0;
		}
	} else {
		$lastrunokay = 1;
	}

	if ( ! file_exists( $vmbackupinprocessfile ) && $lastrunokay == 1 ) {

		foreach ( $files as $file ) {

			if ( strpos( $file, '.swp' ) == false ) {

				$filedata = sb_schedule_fetch( $file );

				//check to see if startdate is in the past
				$started = dateDifference( $filedata['startdatetime'], $thetimefull, 'minutes' );

				if ( $started >= 0 ) {

					$ended = dateDifference( $filedata['enddatetime'], $thetimefull, 'minutes' );

					if ( $ended < 1 ) {
						//enddate is in the future or today
						$thedateonlyodbc = strftime( "%Y-%m-%d" );

						$startbracket = dateDifference( $thedateonlyodbc . ' ' . $filedata['starttime'] . ':00', $thetimefull, 'minutes' );

						if ( $startbracket < 6 && $startbracket > - 1 ) {
							//scheduled within 5 minutes

							$dow                = date( "w" );
							$dom                = date( "j" );
							$lastdayofthismonth = date( "t" );

							if ( empty( $filedata['days'] ) || strpos( " {$filedata['days']}", $dow ) !== false ) {
								//day matches
								if ( empty( $filedata['dom'] ) || $dom == $filedata['dom'] || $filedata['dom'] == 32 && $dom == $lastdayofthismonth ) {

									//match the nth day
									if ( empty( $filedata['numday'] ) || ceil( $dom / 7 ) >= $filedata['numday'] && empty($filedata['days']) || strpos( " {$filedata['days']}", $dow ) !== false && empty($filedata['numday'])) {
										$matchingschedule = 1;
										$configdata       = $filedata['vmstobackup'];

										$schedulename = $filedata['schedulename'];

									}
								}
							}
						}
					}
				}
			}
		}
	}
	$files = null;

	if ( ! empty( $matchingschedule ) ) {

		$snapshotcheck = ovirt_rest_api_call( 'GET', 'vms/' . $settings['uuid_backup_engine'] . '/snapshots' );

		if ( $snapshotcheck > 1 ) {
			sb_email( 'oVirt SimpleBackup Error', 'oVirtSimpleBackup Configuration Issue. Multiple Snapshots on oVirtSimpleBackupVM (' . $snapshotcheck . ').' );
		}

		if ( file_exists( $projectpath . 'config.php' ) ) {


			$backupoktorun = 0;

			sb_pagetitle( 'Automated Backup' );

			if ( ! file_exists( $vmbackupinprocessfile ) ) {
				exec( 'touch ' . $vmbackupinprocessfile );

				exec( 'touch ' . $vmbackupinprocessfile . '_lastrun' );

			}

			$backuplistx   = file_get_contents( $vmbackupinprocessfile );
			$backuplisttmp = explode( "\n", $backuplist );
			$backuplist    = array();
			$backuplist2   = array();

			if ( empty( $backuplistx[0] ) ) {
				//Create Backup UUIDs File for Backup Routines

				if ( empty( $configdata ) ) {
					echo 'No VMs selected to backup';
				} else {

					//get VM list
					$vms = ovirt_rest_api_call( 'GET', 'vms' );

					if ( ! empty( $vms ) ) {
						//prep VMs To Backup
						foreach ( $vms AS $vm ) {

							if ( $vm['id'] != $settings['uuid_backup_engine'] && $vm->name != 'HostedEngine' ) {

								if ( strpos( $configdata, '[' . $vm->name . ']' ) !== false ) {

									echo $vm->name . ' (UUID=' . $vm['id'] . ')';

									exec( 'echo ' . $vm['id'] . ' >> ' . $vmbackupinprocessfile );

									$backuplist[]  = (string) $vm['id'];
									$backuplist2[] = (string) $vm->name;

								}
							}
						}

						$backupoktorun = 1;
					} else {
						echo 'No matching VMs found to backup';

					}
				}

			} else {
				echo 'Backup Already In Process...';

			}
		}

		if ( ! empty( $backupoktorun ) ) {


			if ( empty( $backuplist ) ) {

				sb_email( 'oVirt SimpleBackup Skipped', 'Nothing selected to be backed up.' );

			} else {

				sb_log( '-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*' );
				sb_log( 'Automated Backup - ' . $schedulename . ' -  Starting ....' );
				sb_log( '-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*' );

				$overallstarttime = new DateTime();

				sb_email( 'oVirt SimpleBackup - ' . $schedulename . ' - Starting', 'Backup Starting ... A log will be emailed upon completion.' );

				exec( 'rm ' . $vmbackupemaillog . ' -f' );

				$nowdatetime = strftime( "%m/%d/%Y %H:%M:%S" );

				sb_email_log( '<b>Automated Backup - ' . $schedulename . ' - Starting' . '</b><br/><br/>' );
				$itemnum = 0;

				foreach ( $backuplist as $item ) {

                    $free_bu_mnt_space = sb_check_backup_space();
                    if ($free_bu_mnt_space > 95){
                        sb_log( 'Backing Haleted - Disk Space Remaining on Backup Disk is ' . $free_bu_mnt_space . '%.' );
                    } else {

                        $vmstarttime = new DateTime();

                        sb_log( 'Backup disk space: ' . $free_bu_mnt_space . '%' );
                        sb_log( 'Backing up VM UUID: ' . $item );
                        $nowdatetime = strftime( "%m/%d/%Y %H:%M:%S" );

                        sb_email_log( '<b>Date/Time:</b> ' . $nowdatetime . '<br/>' );
                        sb_email_log( '<b>Backing up VM:</b> ' . $backuplist2[ $itemnum ] . '<br/>' );
                        sb_email_log( '<b>UUID:</b> ' . $item . '<br/>' );

                        $status            = 0;
                        $totaldisksizeofvm = 0;
                        $vmuuid            = $item;
                        while ( $status < 1 ) {
                            sleep( 2 );
                            require( $projectpath . 'comm/snapshot_status.php' );
                            if ( ! file_exists( $vmconfigfile ) ) {
                                die();
                            }
                        }
                        sb_email_log( $reason . '<br/>' );

                        $status = 0;
                        while ( $status < 1 ) {
                            sleep( 2 );
                            require( $projectpath . 'comm/backup_create_path.php' );
                            if ( ! file_exists( $vmconfigfile ) ) {
                                die();
                            }
                        }
                        sb_email_log( $reason . '<br/>' );

                        $status = 0;
                        while ( $status < 1 ) {
                            sleep( 2 );
                            require( $projectpath . 'comm/backup_attach_image.php' );
                            if ( ! empty( $totaldisksize ) ) {
                                $totaldisksizeofvm += $totaldisksize;
                            }
                            if ( ! file_exists( $vmconfigfile ) ) {
                                die();
                            }
                        }
                        sb_email_log( $reason . '<br/>' );

                        sleep( 10 );

                        $vmstarttimeimage = new DateTime();

                        $status = 0;
                        while ( $status < 3 ) {
                            sleep( 2 );
                            require( $projectpath . 'comm/backup_imaging.php' );
                            if ( ! file_exists( $vmconfigfile ) ) {
                                die();
                            }
                        }
                        sb_email_log( $reason . '<br/>' );
                        sb_email_log( '<b>Backup Name:</b> ' . $sb_status['setting2'] . '<br/>' );

                        $vmendtimeimage = new DateTime();

                        $status = 0;
                        while ( $status < 1 ) {
                            sleep( 2 );
                            require( $projectpath . 'comm/backup_detatch_image.php' );
                            if ( ! file_exists( $vmconfigfile ) ) {
                                die();
                            }
                        }
                        sb_email_log( $reason . '<br/>' );

                        $status = 0;
                        while ( $status < 1 ) {
                            sleep( 2 );
                            require( $projectpath . 'comm/snapshot_delete.php' );
                            if ( ! file_exists( $vmconfigfile ) ) {
                                die();
                            }
                        }
                        sb_email_log( $reason . '<br/>' );

                        $filestodelete = null;
                        $backuppath    = $settings['mount_backups'] . '/' . $backuplist2[ $itemnum ] . '/' . $item;
                        exec( 'ls ' . $backuppath, $filestodelete );
                        rsort( $filestodelete );
                        $numsofar       = 1;
                        $arrayofdeleted = array();
                        foreach ( $filestodelete as $filetodelete ) {
                            if ( $numsofar > $settings['retention'] ) {
                                if ( empty( $arrayofdeleted[ $filetodelete ] ) ) {
                                    $arrayofdeleted[ $filetodelete ] = 1;
                                    exec( 'rm ' . $backuppath . '/' . $filetodelete . ' -r -f' );
                                    sb_log( '** Removing ' . $backuplist2[ $itemnum ] . ' Backup ' . $filetodelete . ' based on retention of ' . $settings['retention'] );
                                    sb_email_log( '** Removing ' . $backuplist2[ $itemnum ] . ' Backup ' . $filetodelete . ' based on retention of ' . $settings['retention'] . ' backups.<br/>' );
                                }
                            }
                            $numsofar ++;
                        }

                        $vmendtime              = new DateTime();
                        $dteDiff                = $vmstarttime->diff( $vmendtime );
                        $dteDiffimage           = $vmstarttimeimage->diff( $vmendtimeimage );
                        $durationinseconds      = (int) ( $dteDiff->format( "%H" ) * 3600 ) + (int) ( $dteDiff->format( "%I" ) * 60 ) + (int) ( $dteDiff->format( "%S" ) );
                        $durationinsecondsimage = (int) ( $dteDiffimage->format( "%H" ) * 3600 ) + (int) ( $dteDiffimage->format( "%I" ) * 60 ) + (int) ( $dteDiffimage->format( "%S" ) );
                        if ( $durationinseconds > 3600 ) {
                            $duration = round( $durationinseconds / 3600, 2 ) . ' hours';
                        } else if ( $durationinseconds > 60 ) {
                            $duration = round( $durationinseconds / 60, 1 ) . ' minutes';
                        } else {
                            $duration = round( $durationinseconds ) . ' seconds';
                        }

                        sb_email_log( '<b>VM Disks:</b> ' . $numberofimages . '<br/>' );
                        sb_email_log( '<b>VM Size:</b> ' . round( $totaldisksizeofvm / 1024 / 1024 / 1024 ) . ' GB<br/>' );

                        $compressedfiles = null;
                        exec( 'ls ' . $settings['mount_backups'] . '/' . $sb_status['setting4'] . '/' . $sb_status['setting1'] . '/' . $sb_status['setting2'] . '/*.img.gz', $compressedfiles );
                        $compressedsize = 0;
                        foreach ( $compressedfiles as $compressedfile ) {
                            $compressedsize += round( filesize( $compressedfile ) / 1024 / 1024 / 1024, 2 );
                        }

                        if ( ! empty( $compressedsize ) ) {
                            $comprate = 100 - round( ( ( $compressedsize / round( $totaldisksizeofvm / 1024 / 1024 / 1024 ) ) * 100 ), 2 );
                            sb_email_log( '<b>Compressed Size:</b> ' . '(' . $comprate . '% @ ' . $compressedsize . 'GB)' . '<br/>' );
                        }

                        sb_email_log( '<b>Transfer Speed:</b> ' . round( ( $totaldisksizeofvm / 1024 / 1024 ) / $durationinsecondsimage, 2 ) . ' MB/s<br/>' );
                        sb_email_log( '<b>Backup Time:</b> ' . $duration . '.<br/><br/><hr/><br/>' );
                        $itemnum ++;
                    }
				}
				sb_log
				( '-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*' );
				sb_log( '---- Automated Backup Done' );
				sb_log( '-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*-*' );

				$overallendtime    = new DateTime();
				$dteDiff           = $overallstarttime->diff( $overallendtime );
				$durationinseconds = (int) ( $dteDiff->format( "%H" ) * 3600 ) + (int) ( $dteDiff->format( "%I" ) * 60 ) + (int) ( $dteDiff->format( "%S" ) );
				if ( $durationinseconds > 3600 ) {
					$duration = round( $durationinseconds / 3600, 2 ) . ' hours';
				} else if ( $durationinseconds > 60 ) {
					$duration = round( $durationinseconds / 60, 1 ) . ' minutes';
				} else {
					$duration = round( $durationinseconds ) . ' seconds';
				}

				sb_email_log( '<b>Automated Backups Completed:</b> ' . $duration . '.</b><br/><br/><hr/>' );
				sb_email_log( 'oVIRT_Simple_Backup (version ' . $sb_version . ')' );

				$vmbackupemaillog = file_get_contents( $vmbackupemaillog );

				sb_email( 'oVirt SimpleBackup Completed', $vmbackupemaillog );

			}

			if ( ! empty( $vmbackupinprocessfile ) ) {
				if ( file_exists( $vmbackupinprocessfile ) ) {
					exec( 'rm ' . $vmbackupinprocessfile . ' -f' );
				}
			}
		}
	}