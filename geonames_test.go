package geonames_test

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/sanchezzzhak/geonames"
)

// buildTestDB writes a small binary database with known records to a temp file
// and returns the path.
func buildTestDB(t *testing.T, records []geonames.Record) string {
	t.Helper()
	f, err := os.CreateTemp(t.TempDir(), "test-*.bin")
	if err != nil {
		t.Fatalf("create temp file: %v", err)
	}
	defer f.Close()
	for _, r := range records {
		if _, err := f.Write(geonames.EncodeRecord(r)); err != nil {
			t.Fatalf("write record: %v", err)
		}
	}
	return f.Name()
}

var testRecords = []geonames.Record{
	{Lat: 51.5074, Lon: -0.1278, City: "London", Country: "GB"},
	{Lat: 48.8566, Lon: 2.3522, City: "Paris", Country: "FR"},
	{Lat: 40.7128, Lon: -74.0060, City: "New York", Country: "US"},
	{Lat: 35.6762, Lon: 139.6503, City: "Tokyo", Country: "JP"},
	{Lat: -33.8688, Lon: 151.2093, City: "Sydney", Country: "AU"},
}

func TestOpenAndCount(t *testing.T) {
	path := buildTestDB(t, testRecords)
	db, err := geonames.Open(path)
	if err != nil {
		t.Fatalf("Open: %v", err)
	}
	defer db.Close()

	if got := db.Count(); got != int64(len(testRecords)) {
		t.Errorf("Count() = %d, want %d", got, len(testRecords))
	}
}

func TestNearest(t *testing.T) {
	path := buildTestDB(t, testRecords)
	db, err := geonames.Open(path)
	if err != nil {
		t.Fatalf("Open: %v", err)
	}
	defer db.Close()

	cases := []struct {
		lat, lon    float64
		wantCity    string
		wantCountry string
	}{
		{51.5, -0.12, "London", "GB"},
		{48.85, 2.35, "Paris", "FR"},
		{40.71, -74.01, "New York", "US"},
		{35.68, 139.65, "Tokyo", "JP"},
		{-33.87, 151.21, "Sydney", "AU"},
	}

	for _, tc := range cases {
		rec, err := db.Nearest(tc.lat, tc.lon)
		if err != nil {
			t.Errorf("Nearest(%v,%v): %v", tc.lat, tc.lon, err)
			continue
		}
		if rec.City != tc.wantCity {
			t.Errorf("Nearest(%v,%v).City = %q, want %q", tc.lat, tc.lon, rec.City, tc.wantCity)
		}
		if rec.Country != tc.wantCountry {
			t.Errorf("Nearest(%v,%v).Country = %q, want %q", tc.lat, tc.lon, rec.Country, tc.wantCountry)
		}
	}
}

func TestOpenInvalidFile(t *testing.T) {
	dir := t.TempDir()

	// Non-existent file.
	if _, err := geonames.Open(filepath.Join(dir, "nope.bin")); err == nil {
		t.Error("expected error for non-existent file")
	}

	// File with wrong size (not a multiple of RecordSize).
	bad := filepath.Join(dir, "bad.bin")
	if err := os.WriteFile(bad, []byte("not right"), 0o644); err != nil {
		t.Fatal(err)
	}
	if _, err := geonames.Open(bad); err == nil {
		t.Error("expected error for file with bad size")
	}
}

func TestNearestEmptyDB(t *testing.T) {
	path := buildTestDB(t, nil)
	db, err := geonames.Open(path)
	if err != nil {
		t.Fatalf("Open: %v", err)
	}
	defer db.Close()

	if _, err := db.Nearest(0, 0); err == nil {
		t.Error("expected error for empty database")
	}
}

func TestEncodeDecodeRoundtrip(t *testing.T) {
	original := geonames.Record{Lat: 51.5074, Lon: -0.1278, City: "London", Country: "GB"}
	path := buildTestDB(t, []geonames.Record{original})
	db, err := geonames.Open(path)
	if err != nil {
		t.Fatalf("Open: %v", err)
	}
	defer db.Close()

	got, err := db.Nearest(original.Lat, original.Lon)
	if err != nil {
		t.Fatalf("Nearest: %v", err)
	}
	if got.City != original.City {
		t.Errorf("City round-trip: got %q, want %q", got.City, original.City)
	}
	if got.Country != original.Country {
		t.Errorf("Country round-trip: got %q, want %q", got.Country, original.Country)
	}
	// Lat/lon are stored as float32 so expect small rounding.
	const eps = 0.001
	if d := got.Lat - original.Lat; d > eps || d < -eps {
		t.Errorf("Lat round-trip: got %v, want %v", got.Lat, original.Lat)
	}
	if d := got.Lon - original.Lon; d > eps || d < -eps {
		t.Errorf("Lon round-trip: got %v, want %v", got.Lon, original.Lon)
	}
}
