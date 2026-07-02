<?php

declare(strict_types=1);

namespace CircuitMap\Tests\Unit\Kml;

use CircuitMap\Services\Kml\KmlParseException;
use CircuitMap\Services\Kml\KmlParser;
use CircuitMap\Services\Kml\KmlValidator;
use PHPUnit\Framework\TestCase;

final class KmlValidatorTest extends TestCase
{
    private KmlParser $parser;
    private KmlValidator $validator;

    protected function setUp(): void
    {
        $this->parser = new KmlParser();
        $this->validator = new KmlValidator();
    }

    private function parse(string $xml): \DOMDocument
    {
        return $this->parser->parse($xml);
    }

    public function testValidPointLineStringAndPolygonAreAccepted(): void
    {
        $xml = <<<'KML'
<?xml version="1.0"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
  <Document>
    <Placemark><Point><coordinates>-122.4,37.7,0</coordinates></Point></Placemark>
    <Placemark><LineString><coordinates>-122.4,37.7 -122.5,37.8</coordinates></LineString></Placemark>
    <Placemark>
      <Polygon>
        <outerBoundaryIs>
          <LinearRing>
            <coordinates>0,0 1,0 1,1 0,0</coordinates>
          </LinearRing>
        </outerBoundaryIs>
      </Polygon>
    </Placemark>
    <Placemark>
      <MultiGeometry>
        <Point><coordinates>0,0</coordinates></Point>
        <LineString><coordinates>0,0 1,1</coordinates></LineString>
      </MultiGeometry>
    </Placemark>
  </Document>
</kml>
KML;

        $this->validator->validate($this->parse($xml));
        $this->addToAssertionCount(1);
    }

    public function testMissingPlacemarkIsRejected(): void
    {
        $xml = '<?xml version="1.0"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document/></kml>';

        $this->expectException(KmlParseException::class);
        $this->expectExceptionMessageMatches('/at least one Placemark/');
        $this->validator->validate($this->parse($xml));
    }

    public function testUnsupportedGeometryTypeIsRejected(): void
    {
        $xml = <<<'KML'
<?xml version="1.0"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
  <Document>
    <Placemark><Model><foo/></Model></Placemark>
  </Document>
</kml>
KML;

        $this->expectException(KmlParseException::class);
        $this->expectExceptionMessageMatches('/no supported geometry/');
        $this->validator->validate($this->parse($xml));
    }

    public function testNonNumericCoordinatesAreRejected(): void
    {
        $xml = <<<'KML'
<?xml version="1.0"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
  <Document>
    <Placemark><Point><coordinates>not,a,number</coordinates></Point></Placemark>
  </Document>
</kml>
KML;

        $this->expectException(KmlParseException::class);
        $this->expectExceptionMessageMatches('/Malformed coordinate tuple/');
        $this->validator->validate($this->parse($xml));
    }

    public function testWrongCoordinateTupleCountIsRejected(): void
    {
        $xml = <<<'KML'
<?xml version="1.0"?>
<kml xmlns="http://www.opengis.net/kml/2.2">
  <Document>
    <Placemark><Point><coordinates>0,0 1,1</coordinates></Point></Placemark>
  </Document>
</kml>
KML;

        $this->expectException(KmlParseException::class);
        $this->expectExceptionMessageMatches('/coordinate tuple/');
        $this->validator->validate($this->parse($xml));
    }

    public function testRootElementMustBeKml(): void
    {
        $xml = '<?xml version="1.0"?><notkml><Placemark/></notkml>';

        $this->expectException(KmlParseException::class);
        $this->expectExceptionMessageMatches('/Root element/');
        $this->validator->validate($this->parse($xml));
    }
}
