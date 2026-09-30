<?php

/**
 * Tests for Disciple_Tools_Migration_Import_Engine::wp_post_dates_from_record().
 */
class Post_Dates_From_Record_Test extends TestCase {

    /**
     * DT_Posts::get_post exports post_date as timestamp + formatted.
     */
    public function test_dt_post_date_timestamp_shape() {
        $ts = strtotime( '2018-06-06 12:46:00 UTC' );
        $this->assertIsInt( $ts );
        $this->assertGreaterThan( 0, $ts );

        $dates = Disciple_Tools_Migration_Import_Engine::wp_post_dates_from_record(
            [
                'post_date' => [
                    'timestamp' => $ts,
                    'formatted' => 'June 6, 2018',
                ],
            ]
        );

        $this->assertIsArray( $dates );
        $this->assertSame( gmdate( 'Y-m-d H:i:s', $ts ), $dates['post_date_gmt'] );
        $this->assertSame( get_date_from_gmt( $dates['post_date_gmt'] ), $dates['post_date'] );
    }

    /**
     * Legacy flat post_date_gmt strings are supported.
     */
    public function test_flat_post_date_gmt() {
        $dates = Disciple_Tools_Migration_Import_Engine::wp_post_dates_from_record(
            [
                'post_date_gmt' => '2018-06-06 12:46:00',
            ]
        );

        $this->assertIsArray( $dates );
        $this->assertSame( '2018-06-06 12:46:00', $dates['post_date_gmt'] );
        $this->assertSame( get_date_from_gmt( '2018-06-06 12:46:00' ), $dates['post_date'] );
    }

    /**
     * Missing or zero timestamps fall back to null (WordPress uses import time on insert).
     */
    public function test_returns_null_when_no_valid_date() {
        $this->assertNull(
            Disciple_Tools_Migration_Import_Engine::wp_post_dates_from_record( [] )
        );
        $this->assertNull(
            Disciple_Tools_Migration_Import_Engine::wp_post_dates_from_record(
                [
                    'post_date' => [
                        'timestamp' => 0,
                        'formatted' => '',
                    ],
                ]
            )
        );
        $this->assertNull(
            Disciple_Tools_Migration_Import_Engine::wp_post_dates_from_record(
                [
                    'post_date_gmt' => '0000-00-00 00:00:00',
                ]
            )
        );
    }
}
