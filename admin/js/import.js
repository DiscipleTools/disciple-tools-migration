/**
 * Disciple.Tools Migration - Import flow (AJAX, progress, confirmation)
 */
( function( $ ) {
    'use strict';

    let $modal, $progress, $confirmInput, $confirmBtn, $cancelBtn, $summary;
    let $progressBar, $progressText, $stepList, $currentPhase, $cancelImport;
    let $errorDetails, $errorScroll;

    let cancelled = false;
    let phases = [];
    let currentPhaseIndex = 0;
    let totalSteps = 0;
    let completedSteps = 0;

    const CONFIRM_WORD = 'IMPORT';

    function getSelectedSettings() {
        const out = [];
        $( '.dt-migration-setting-checkbox:checked:not(:disabled)' ).each( function() {
            out.push( $( this ).val() );
        } );
        return out;
    }

    function getSelectedRecords() {
        const out = {};
        $( '.dt-migration-record-checkbox:checked' ).each( function() {
            const pt = $( this ).data( 'post-type' );
            const count = $( this ).data( 'record-count' ) || 0;
            out[ pt ] = { count };
        } );
        return out;
    }

    function buildSummary( settings, records ) {
        const parts = [];
        if ( settings.length ) {
            const labels = {
                general_settings: 'General Settings',
                custom_lists: 'Custom Lists',
                tiles: 'Tiles',
                fields: 'Fields',
                roles: 'Roles',
                workflows: 'Workflows'
            };
            const names = settings.map( s => labels[ s ] || s );
            parts.push( 'Settings: ' + names.join( ', ' ) );
        }
        if ( Object.keys( records ).length ) {
            const recParts = Object.keys( records ).map( pt => pt + ' (' + ( records[ pt ].count || 0 ) + ' records)' );
            parts.push( 'Records: ' + recParts.join( ', ' ) );
        }
        return parts.join( '. ' );
    }

    function buildPhases() {
        const settings = getSelectedSettings();
        const records = getSelectedRecords();
        const phases = [];

        if ( settings.length ) {
            phases.push( {
                type: 'settings',
                label: 'Import settings (' + settings.join( ', ' ) + ')',
                settings: settings,
                records: null
            } );
        }
        const order = [ 'peoplegroups', 'contacts', 'groups', 'trainings' ];
        const rest = Object.keys( records ).filter( pt => ! order.includes( pt ) );
        const ordered = order.filter( pt => records[ pt ] ).concat( rest );
        ordered.forEach( pt => {
            if ( records[ pt ] ) {
                phases.push( {
                    type: 'records',
                    post_type: pt,
                    label: 'Import ' + pt + ' (' + ( records[ pt ].count || 0 ) + ' records)',
                    records: records
                } );
            }
        } );
        return phases;
    }

    function getPhaseConfirmMessage( phase ) {
        if ( phase.type === 'settings' ) {
            return 'This will overwrite the selected settings on this site.';
        }
        return 'This will delete existing ' + phase.post_type + ' and replace them with records from Server A. Record IDs will be preserved for relationships.';
    }

    function setProgress( percent ) {
        const n = ( typeof percent === 'number' && ! isNaN( percent ) ) ? Math.round( percent ) : 0;
        $progressBar.css( 'width', n + '%' );
        $progressText.text( n + '%' );
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

    function showModal( phase ) {
        $summary.text( getPhaseConfirmMessage( phase ) );
        $confirmInput.val( '' ).prop( 'disabled', false );
        $confirmBtn.prop( 'disabled', true );
        cancelled = false;
        $modal.show();
    }

    function hideModal() {
        $modal.hide();
    }

    function showProgress() {
        $progress.show();
        $errorDetails.hide();
        $errorScroll.text( '' );
        phases.forEach( ( p, i ) => addStep( p.label, i === 0 ? 'active' : '' ) );
    }

    function showError( message ) {
        $errorScroll.text( message || '' );
        $errorDetails.toggle( !! message );
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

                function fetchBatch() {
                    if ( cancelled ) {
                        resolve( { cancelled: true } );
                        return;
                    }
                    $.post( dtMigrationImport.ajaxUrl, {
                        action: 'dt_migration_import_batch',
                        nonce: dtMigrationImport.nonce,
                        step: 'records',
                        post_type: phase.post_type,
                        offset: offset
                    } ).done( function( r ) {
                        if ( r.success ) {
                            const d = r.data;
                            totalImported += d.imported || 0;
                            const pct = totalExpected ? ( totalImported / totalExpected ) * 100 : 100;
                            const phasePct = totalSteps ? ( currentPhaseIndex / totalSteps ) * 100 + ( pct / totalSteps ) : 0;
                            setProgress( Math.min( 100, phasePct ) );

                            if ( d.has_more ) {
                                offset = d.next_offset || ( offset + 50 );
                                fetchBatch();
                            } else {
                                resolve( { imported: totalImported } );
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
            setProgress( 100 );
            $currentPhase.text( 'Import complete.' );
            $cancelImport.hide();
            return;
        }
        const phase = phases[ currentPhaseIndex ];
        showModal( phase );
        $confirmInput.val( '' );
        $confirmBtn.prop( 'disabled', true );
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
        markStepActive( currentPhaseIndex );
        runPhase( phase ).then( function( result ) {
            if ( result && result.cancelled ) {
                $currentPhase.text( 'Import cancelled.' );
                $cancelImport.hide();
                return;
            }
            markStepDone( currentPhaseIndex );
            completedSteps++;
            currentPhaseIndex++;
            startNextPhase();
        } ).catch( function( err ) {
            $currentPhase.text( 'Import failed.' );
            showError( err );
            $cancelImport.hide();
        } );
    }

    function onConfirmClick() {
        const val = $confirmInput.val().trim().toUpperCase();
        if ( val === CONFIRM_WORD ) {
            runCurrentPhase();
        }
    }

    function init() {
        $modal = $( '#dt-migration-import-modal' );
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
        $errorDetails = $( '#dt-migration-error-details' );
        $errorScroll = $( '.dt-migration-error-scroll' );

        if ( ! $modal.length || ! $( '.dt-migration-start-import' ).length ) {
            return;
        }

        $confirmInput.on( 'input', function() {
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
            const settings = getSelectedSettings();
            const records = getSelectedRecords();
            if ( ! settings.length && ! Object.keys( records ).length ) {
                alert( 'Please select at least one setting type or record type to import.' );
                return;
            }
            phases = buildPhases();
            if ( ! phases.length ) {
                return;
            }
            totalSteps = phases.length;
            currentPhaseIndex = 0;
            $summary.text( buildSummary( settings, records ) );
            showModal( phases[ 0 ] );
        } );

        $( '.dt-migration-select-all-settings' ).on( 'change', function() {
            const checked = $( this ).prop( 'checked' );
            $( '.dt-migration-setting-checkbox:not(:disabled)' ).prop( 'checked', checked );
        } );

        $( '.dt-migration-select-all-records' ).on( 'change', function() {
            const checked = $( this ).prop( 'checked' );
            $( '.dt-migration-record-checkbox' ).prop( 'checked', checked );
        } );
    }

    $( document ).ready( init );

} )( jQuery );
