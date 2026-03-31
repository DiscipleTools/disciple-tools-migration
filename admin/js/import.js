/**
 * Disciple.Tools Migration - Import flow (AJAX, progress, confirmation)
 */
( function( $ ) {
    'use strict';

    const strings = ( typeof dtMigrationImport !== 'undefined' && dtMigrationImport.strings ) ? dtMigrationImport.strings : {};

    function t( key, fallback ) {
        return strings[ key ] || fallback;
    }

    let $modal, $modalTitle, $progress, $confirmInput, $confirmBtn, $cancelBtn, $summary;
    let $confirmGate, $modalWarning;
    let $progressBar, $progressText, $stepList, $currentPhase, $cancelImport, $importSpinner;
    let $errorDetails, $errorScroll;

    let cancelled = false;
    let phases = [];
    let currentPhaseIndex = 0;
    let totalSteps = 0;
    let completedSteps = 0;
    let activeImportChannel = 'api';
    let isSlimConfirmMode = false;
    /** First records batch of this run sends init_records_import (clears stale deferred queue if settings step was skipped). */
    let sentRecordsImportInit = false;
    /** Per-record or connection-pass issues logged while import continues. */
    let hadNonFatalImportIssues = false;

    const CONFIRM_WORD = 'IMPORT';

    function escapeHtml( s ) {
        const div = document.createElement( 'div' );
        div.textContent = s == null ? '' : String( s );
        return div.innerHTML;
    }

    function getSelectedSettings( $scope ) {
        const root = $scope && $scope.length ? $scope : $( document );
        const out = [];
        root.find( '.dt-migration-setting-checkbox:checked:not(:disabled)' ).each( function() {
            out.push( $( this ).val() );
        } );
        return out;
    }

    function getSelectedRecords( $scope ) {
        const root = $scope && $scope.length ? $scope : $( document );
        const out = {};
        root.find( '.dt-migration-record-checkbox:checked' ).each( function() {
            const pt = $( this ).data( 'post-type' );
            const count = $( this ).data( 'record-count' ) || 0;
            out[ pt ] = { count };
        } );
        return out;
    }

    function buildPhases( $section ) {
        const settings = getSelectedSettings( $section );
        const records = getSelectedRecords( $section );
        const phasesOut = [];

        if ( settings.length ) {
            phasesOut.push( {
                type: 'settings',
                label: 'Import settings (' + settings.join( ', ' ) + ')',
                settings: settings,
                records: null
            } );
        }
        const order = [ 'peoplegroups', 'groups', 'contacts', 'trainings' ];
        const rest = Object.keys( records ).filter( pt => ! order.includes( pt ) );
        const ordered = order.filter( pt => records[ pt ] ).concat( rest );
        const recordPts = ordered.filter( pt => records[ pt ] );
        recordPts.forEach( ( pt, idx ) => {
            phasesOut.push( {
                type: 'records',
                post_type: pt,
                label: 'Import ' + pt + ' (' + ( records[ pt ].count || 0 ) + ' records)',
                records: records,
                isLastRecordTypePhase: idx === recordPts.length - 1
            } );
        } );
        return phasesOut;
    }

    function getPhaseConfirmMessage( phase ) {
        if ( phase.type === 'settings' ) {
            return 'This will overwrite the selected settings on this site.';
        }
        const source = activeImportChannel === 'file' ? 'the uploaded export file' : 'Server A';
        return 'This will delete existing ' + phase.post_type + ' and replace them with records from ' + source + '. Record IDs will be preserved for relationships.';
    }

    function buildSlimModalSummaryHtml( completedPhase, nextPhase ) {
        const completedL = t( 'completedLabel', 'Completed:' );
        const nextL = t( 'nextLabel', 'Next:' );
        return (
            '<p class="dt-migration-slim-completed"><strong>' + escapeHtml( completedL ) + '</strong> ' + escapeHtml( completedPhase.label ) + '</p>' +
            '<p class="dt-migration-slim-next"><strong>' + escapeHtml( nextL ) + '</strong> ' + escapeHtml( nextPhase.label ) + '</p>' +
            '<p class="dt-migration-slim-detail">' + escapeHtml( getPhaseConfirmMessage( nextPhase ) ) + '</p>'
        );
    }

    function showFullModal( phase ) {
        isSlimConfirmMode = false;
        $modal.removeClass( 'dt-migration-modal--slim' );
        $modalTitle.text( t( 'confirmImport', 'Confirm Import' ) );
        $modalWarning.show();
        $confirmGate.show();
        $summary.text( getPhaseConfirmMessage( phase ) );
        $confirmInput.val( '' ).prop( 'disabled', false );
        $confirmBtn.text( t( 'confirm', 'Confirm' ) ).prop( 'disabled', true );
        cancelled = false;
        $modal.show();
    }

    function showSlimModal( completedPhase, nextPhase ) {
        isSlimConfirmMode = true;
        $modal.addClass( 'dt-migration-modal--slim' );
        $modalTitle.text( t( 'continueImport', 'Continue import' ) );
        $modalWarning.hide();
        $confirmGate.hide();
        $summary.html( buildSlimModalSummaryHtml( completedPhase, nextPhase ) );
        $confirmBtn.text( t( 'continue', 'Continue' ) ).prop( 'disabled', false );
        cancelled = false;
        $modal.show();
    }

    function setProgress( percent ) {
        const n = ( typeof percent === 'number' && ! isNaN( percent ) ) ? Math.round( percent ) : 0;
        $progressBar.css( 'width', n + '%' );
        $progressText.text( n + '%' );
    }

    function setImportSpinner( on ) {
        if ( ! $importSpinner || ! $importSpinner.length ) {
            return;
        }
        $importSpinner.prop( 'hidden', ! on );
        $importSpinner.attr( 'aria-hidden', on ? 'false' : 'true' );
        $progress.attr( 'aria-busy', on ? 'true' : 'false' );
    }

    function addStep( label, status ) {
        const cls = status === 'done' ? 'done' : ( status === 'active' ? 'active' : '' );
        $stepList.append( '<li class="' + cls + '">' + label + '</li>' );
    }

    function markStepDone( index ) {
        $stepList.find( 'li' ).eq( index ).addClass( 'done' ).removeClass( 'active' );
    }

    function markStepActive( index ) {
        $stepList.find( 'li' ).removeClass( 'active' );
        $stepList.find( 'li' ).eq( index ).addClass( 'active' );
    }

    function hideModal() {
        $modal.hide();
    }

    function showProgress() {
        $progress.show();
        $errorDetails.hide();
        $errorScroll.text( '' );
        hadNonFatalImportIssues = false;
        phases.forEach( ( p, i ) => addStep( p.label, i === 0 ? 'active' : '' ) );
    }

    function appendImportLogLines( lines ) {
        if ( ! Array.isArray( lines ) || ! lines.length ) {
            return;
        }
        const block = lines.join( '\n' );
        const cur = $errorScroll.text();
        $errorScroll.text( cur ? cur + '\n' + block : block );
        $errorDetails.show();
        hadNonFatalImportIssues = true;
    }

    function showError( message ) {
        $errorScroll.text( message || '' );
        $errorDetails[ message ? 'show' : 'hide' ]();
    }

    function runPhase( phase ) {
        return new Promise( ( resolve, reject ) => {
            if ( cancelled ) {
                resolve( { cancelled: true } );
                return;
            }
            $currentPhase.text( phase.label + '...' );

            if ( phase.type === 'settings' ) {
                $.post( dtMigrationImport.ajaxUrl, {
                    action: 'dt_migration_import_batch',
                    nonce: dtMigrationImport.nonce,
                    import_channel: activeImportChannel,
                    step: 'settings',
                    settings_selected: phase.settings
                } ).done( function( r ) {
                    if ( r.success ) {
                        resolve( r.data );
                    } else {
                        reject( r.data && r.data.message ? r.data.message : 'Settings import failed' );
                    }
                } ).fail( function( xhr ) {
                    reject( xhr.statusText || 'Request failed' );
                } );
                return;
            }

            if ( phase.type === 'records' ) {
                let offset = 0;
                let totalImported = 0;
                const totalExpected = phase.records[ phase.post_type ] ? phase.records[ phase.post_type ].count : 0;

                function finishRecordPhase() {
                    if ( ! phase.isLastRecordTypePhase ) {
                        resolve( { imported: totalImported } );
                        return;
                    }
                    $currentPhase.text( 'Applying connection fields…' );
                    $.post( dtMigrationImport.ajaxUrl, {
                        action: 'dt_migration_import_batch',
                        nonce: dtMigrationImport.nonce,
                        import_channel: activeImportChannel,
                        step: 'apply_deferred_connections'
                    } ).done( function( r2 ) {
                        if ( r2.success ) {
                            const d2 = r2.data || {};
                            appendImportLogLines( d2.connection_errors );
                            resolve( { imported: totalImported } );
                        } else {
                            reject( r2.data && r2.data.message ? r2.data.message : 'Connection pass failed' );
                        }
                    } ).fail( function( xhr ) {
                        reject( xhr.statusText || 'Request failed' );
                    } );
                }

                function fetchBatch() {
                    if ( cancelled ) {
                        resolve( { cancelled: true } );
                        return;
                    }
                    const payload = {
                        action: 'dt_migration_import_batch',
                        nonce: dtMigrationImport.nonce,
                        import_channel: activeImportChannel,
                        step: 'records',
                        post_type: phase.post_type,
                        offset: offset
                    };
                    if ( offset === 0 && ! sentRecordsImportInit ) {
                        payload.init_records_import = '1';
                        sentRecordsImportInit = true;
                    }
                    $.post( dtMigrationImport.ajaxUrl, payload ).done( function( r ) {
                        if ( r.success ) {
                            const d = r.data;
                            appendImportLogLines( d.record_errors );
                            totalImported += d.imported || 0;
                            const pct = totalExpected ? ( totalImported / totalExpected ) * 100 : 100;
                            const phasePct = totalSteps ? ( currentPhaseIndex / totalSteps ) * 100 + ( pct / totalSteps ) : 0;
                            setProgress( Math.min( 100, phasePct ) );

                            if ( d.has_more ) {
                                offset = d.next_offset || ( offset + 50 );
                                fetchBatch();
                            } else {
                                finishRecordPhase();
                            }
                        } else {
                            reject( r.data && r.data.message ? r.data.message : 'Records import failed' );
                        }
                    } ).fail( function( xhr ) {
                        reject( xhr.statusText || 'Request failed' );
                    } );
                }
                fetchBatch();
            }
        } );
    }

    function startNextPhase() {
        if ( currentPhaseIndex >= phases.length ) {
            setImportSpinner( false );
            setProgress( 100 );
            $currentPhase.text(
                hadNonFatalImportIssues
                    ? t( 'importCompleteWithLog', 'Import complete. Review logged issues below.' )
                    : 'Import complete.'
            );
            $cancelImport.hide();
            return;
        }
        setImportSpinner( false );
        const phase = phases[ currentPhaseIndex ];
        if ( currentPhaseIndex === 0 ) {
            showFullModal( phase );
        } else {
            showSlimModal( phases[ currentPhaseIndex - 1 ], phase );
        }
    }

    function runCurrentPhase() {
        if ( currentPhaseIndex >= phases.length ) {
            return;
        }
        const phase = phases[ currentPhaseIndex ];
        hideModal();
        if ( ! $progress.is( ':visible' ) ) {
            showProgress();
            setProgress( 0 );
            $cancelImport.show();
        }
        setImportSpinner( true );
        markStepActive( currentPhaseIndex );
        runPhase( phase ).then( function( result ) {
            if ( result && result.cancelled ) {
                setImportSpinner( false );
                $currentPhase.text( 'Import cancelled.' );
                $cancelImport.hide();
                return;
            }
            markStepDone( currentPhaseIndex );
            completedSteps++;
            currentPhaseIndex++;
            startNextPhase();
        } ).catch( function( err ) {
            setImportSpinner( false );
            $currentPhase.text( 'Import failed.' );
            showError( err );
            $cancelImport.hide();
        } );
    }

    function onConfirmClick() {
        if ( isSlimConfirmMode ) {
            runCurrentPhase();
            return;
        }
        const val = $confirmInput.val().trim().toUpperCase();
        if ( val === CONFIRM_WORD ) {
            runCurrentPhase();
        }
    }

    function init() {
        $modal = $( '#dt-migration-import-modal' );
        $modalTitle = $( '#dt-migration-modal-title' );
        $modalWarning = $( '.dt-migration-modal-warning' );
        $confirmGate = $( '.dt-migration-modal-confirm-gate' );
        $progress = $( '#dt-migration-progress-panel' );
        $confirmInput = $( '#dt-migration-confirm-input' );
        $confirmBtn = $( '.dt-migration-modal-confirm' );
        $cancelBtn = $( '.dt-migration-modal-cancel' );
        $summary = $( '.dt-migration-modal-summary' );
        $progressBar = $( '.dt-migration-progress-fill' );
        $progressText = $( '.dt-migration-progress-text' );
        $stepList = $( '.dt-migration-step-list' );
        $currentPhase = $( '.dt-migration-current-phase' );
        $cancelImport = $( '.dt-migration-cancel-import' );
        $importSpinner = $( '.dt-migration-import-spinner' );
        $errorDetails = $( '#dt-migration-error-details' );
        $errorScroll = $( '.dt-migration-error-scroll' );

        if ( ! $modal.length || ! $( '.dt-migration-start-import' ).length ) {
            return;
        }

        $confirmInput.on( 'input', function() {
            if ( isSlimConfirmMode ) {
                return;
            }
            const val = $( this ).val().trim().toUpperCase();
            $confirmBtn.prop( 'disabled', val !== CONFIRM_WORD );
        } );

        $confirmInput.on( 'keydown', function( e ) {
            if ( e.key === 'Enter' ) {
                onConfirmClick();
            }
        } );

        $confirmBtn.on( 'click', onConfirmClick );

        $cancelBtn.on( 'click', function() {
            hideModal();
        } );

        $( '.dt-migration-modal-overlay' ).on( 'click', function() {
            hideModal();
        } );

        $cancelImport.on( 'click', function() {
            cancelled = true;
            $( this ).prop( 'disabled', true ).text( 'Cancelling...' );
        } );

        $( '.dt-migration-start-import' ).on( 'click', function() {
            const $section = $( this ).closest( '.dt-migration-import-section' );
            const fromBtn = $( this ).data( 'importChannel' );
            activeImportChannel = fromBtn === 'file' ? 'file' : 'api';

            const settings = getSelectedSettings( $section );
            const records = getSelectedRecords( $section );
            if ( ! settings.length && ! Object.keys( records ).length ) {
                alert( 'Please select at least one setting type or record type to import.' );
                return;
            }
            phases = buildPhases( $section );
            if ( ! phases.length ) {
                return;
            }
            totalSteps = phases.length;
            currentPhaseIndex = 0;
            completedSteps = 0;
            sentRecordsImportInit = false;
            startNextPhase();
        } );

        $( document ).on( 'change', '.dt-migration-select-all-settings', function() {
            const $section = $( this ).closest( '.dt-migration-import-section' );
            const checked = $( this ).prop( 'checked' );
            $section.find( '.dt-migration-setting-checkbox:not(:disabled)' ).prop( 'checked', checked );
        } );

        $( document ).on( 'change', '.dt-migration-select-all-records', function() {
            const $section = $( this ).closest( '.dt-migration-import-section' );
            const checked = $( this ).prop( 'checked' );
            $section.find( '.dt-migration-record-checkbox' ).prop( 'checked', checked );
        } );
    }

    $( document ).ready( init );

} )( jQuery );
