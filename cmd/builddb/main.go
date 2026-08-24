// Command builddb downloads the GeoNames cities500.txt dataset and converts it
// into the compact binary database format used by the geonames package.
//
// Usage:
//
//	go run ./cmd/builddb -input cities500.txt -output cities500.bin
//
// If -input is omitted the file is downloaded from geonames.org automatically.
package main

import (
	"archive/zip"
	"bufio"
	"flag"
	"fmt"
	"io"
	"log"
	"net/http"
	"os"
	"path/filepath"
	"strconv"
	"strings"

	"github.com/sanchezzzhak/geonames"
)

const defaultURL = "https://download.geonames.org/export/dump/cities500.zip"

func main() {
	input := flag.String("input", "", "path to cities500.txt (downloaded if empty)")
	output := flag.String("output", "cities500.bin", "output binary database path")
	flag.Parse()

	var r io.ReadCloser

	if *input != "" {
		f, err := os.Open(*input)
		if err != nil {
			log.Fatalf("open input: %v", err)
		}
		defer f.Close()
		r = f
	} else {
		tmpDir, err := os.MkdirTemp("", "geonames-build-*")
		if err != nil {
			log.Fatalf("mktemp: %v", err)
		}
		defer os.RemoveAll(tmpDir)

		zipPath := filepath.Join(tmpDir, "cities500.zip")
		log.Printf("downloading %s …", defaultURL)
		if err := download(defaultURL, zipPath); err != nil {
			log.Fatalf("download: %v", err)
		}

		zr, err := zip.OpenReader(zipPath)
		if err != nil {
			log.Fatalf("open zip: %v", err)
		}
		defer zr.Close()

		var zf *zip.File
		for _, f := range zr.File {
			if f.Name == "cities500.txt" {
				zf = f
				break
			}
		}
		if zf == nil {
			log.Fatalf("cities500.txt not found in zip")
		}
		rc, err := zf.Open()
		if err != nil {
			log.Fatalf("open zip entry: %v", err)
		}
		defer rc.Close()
		r = rc
	}

	out, err := os.Create(*output)
	if err != nil {
		log.Fatalf("create output: %v", err)
	}
	defer out.Close()

	w := bufio.NewWriter(out)
	scanner := bufio.NewScanner(r)
	scanner.Buffer(make([]byte, 1<<20), 1<<20)

	n := 0
	for scanner.Scan() {
		line := scanner.Text()
		if line == "" || line[0] == '#' {
			continue
		}
		rec, ok := parseLine(line)
		if !ok {
			continue
		}
		if _, err := w.Write(geonames.EncodeRecord(rec)); err != nil {
			log.Fatalf("write record: %v", err)
		}
		n++
	}
	if err := scanner.Err(); err != nil {
		log.Fatalf("scan: %v", err)
	}
	if err := w.Flush(); err != nil {
		log.Fatalf("flush: %v", err)
	}

	log.Printf("wrote %d records to %s", n, *output)
}

// parseLine parses one tab-separated line from cities500.txt.
// GeoNames columns: geonameid, name, asciiname, alternatenames,
//
//	latitude(4), longitude(5), …, country code(8), …
func parseLine(line string) (geonames.Record, bool) {
	fields := strings.Split(line, "\t")
	if len(fields) < 9 {
		return geonames.Record{}, false
	}
	lat, err := strconv.ParseFloat(fields[4], 64)
	if err != nil {
		return geonames.Record{}, false
	}
	lon, err := strconv.ParseFloat(fields[5], 64)
	if err != nil {
		return geonames.Record{}, false
	}
	name := fields[2] // asciiname – always ASCII, fits in 64 bytes cleanly
	if name == "" {
		name = fields[1]
	}
	country := fields[8]
	if len(country) > 2 {
		country = country[:2]
	}
	return geonames.Record{Lat: lat, Lon: lon, City: name, Country: country}, true
}

func download(url, dst string) error {
	resp, err := http.Get(url) //nolint:gosec
	if err != nil {
		return err
	}
	defer resp.Body.Close()
	if resp.StatusCode != http.StatusOK {
		return fmt.Errorf("HTTP %s", resp.Status)
	}
	f, err := os.Create(dst)
	if err != nil {
		return err
	}
	defer f.Close()
	_, err = io.Copy(f, resp.Body)
	return err
}
