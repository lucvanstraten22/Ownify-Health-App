package com.healthapp.android.jolu;

import androidx.health.connect.client.records.metadata.DataOrigin;
import androidx.health.connect.client.records.metadata.Metadata;
import java.time.Instant;

/** Metadata as Health Connect fills it on read: an id and the app that wrote the record. Test only. */
final class TestMetadata {
    private TestMetadata() {}

    static Metadata of(String id, String origin) {
        return new Metadata(Metadata.RECORDING_METHOD_AUTOMATICALLY_RECORDED, id, new DataOrigin(origin),
            Instant.now(), null, 0L, null);
    }
}
