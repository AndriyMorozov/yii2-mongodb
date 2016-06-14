<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\mongodb\file;

use MongoDB\BSON\ObjectID;
use Yii;
use yii\base\InvalidConfigException;
use yii\base\Object;
use yii\helpers\FileHelper;
use yii\helpers\StringHelper;

/**
 * Download represents the GridFS download operation.
 *
 * @property array|ObjectID $document document to be downloaded.
 * @property \MongoDB\Driver\Cursor $chunkCursor cursor for the file chunks. This property is read-only.
 * @property \Iterator $chunkIterator  iterator for [[chunkCursor]]. This property is read-only.
 *
 * @author Paul Klimov <klimov.paul@gmail.com>
 * @since 2.1
 */
class Download extends Object
{
    /**
     * @var Collection file collection to be used.
     */
    public $collection;

    /**
     * @var array|ObjectID document to be downloaded.
     */
    private $_document;
    /**
     * @var \MongoDB\Driver\Cursor cursor for the file chunks.
     */
    private $_chunkCursor;
    /**
     * @var \Iterator iterator for [[chunkCursor]].
     */
    private $_chunkIterator;


    /**
     * @return array document to be downloaded.
     * @throws InvalidConfigException on invalid document configuration.
     */
    public function getDocument()
    {
        if (!is_array($this->_document)) {
            if (is_scalar($this->_document) || $this->_document instanceof ObjectID) {
                $document = $this->collection->findOne(['_id' => $this->_document]);
                if (empty($document)) {
                    throw new InvalidConfigException('Document id=' . $this->_document . ' does not exist at collection "' . $this->collection->getFullName() . '"');
                }
                $this->_document = $document;
            } else {
                $this->_document = (array)$this->_document;
            }
        }
        return $this->_document;
    }

    /**
     * @param array|ObjectID $document
     */
    public function setDocument($document)
    {
        $this->_document = $document;
    }

    /**
     * Returns the size of the associated file.
     * @return integer file size.
     */
    public function getSize()
    {
        $document = $this->getDocument();
        if (isset($document['length'])) {
            return $document['length'];
        }
        return 0;
    }

    /**
     * Returns associated file's filename.
     * @return string|null file name.
     */
    public function getFilename()
    {
        $document = $this->getDocument();
        if (isset($document['filename'])) {
            return $document['filename'];
        }
        return null;
    }

    /**
     * @param boolean $refresh whether to recreate cursor, if it is already exist.
     * @return \MongoDB\Driver\Cursor chuck list cursor.
     * @throws InvalidConfigException
     */
    public function getChunkCursor($refresh = false)
    {
        if ($refresh || $this->_chunkCursor === null) {
            $file = $this->getDocument();
            $this->_chunkCursor = $this->collection->getChunkCollection()->find(
                ['files_id' => $file['_id']],
                [],
                ['sort' => ['n' => 1]]
            );
        }
        return $this->_chunkCursor;
    }

    /**
     * @param boolean $refresh whether to recreate iterator, if it is already exist.
     * @return \Iterator chuck cursor iterator.
     */
    public function getChunkIterator($refresh = false)
    {
        if ($refresh || $this->_chunkIterator === null) {
            $this->_chunkIterator = new \IteratorIterator($this->getChunkCursor($refresh));
            $this->_chunkIterator->rewind();
        }
        return $this->_chunkIterator;
    }

    /**
     * Saves file into the given stream.
     * @param resource $stream stream, which file should be saved to.
     * @return integer number of written bytes.
     */
    public function toStream($stream)
    {
        $bytesWritten = 0;
        foreach ($this->getChunkCursor() as $chunk) {
            $bytesWritten += fwrite($stream, $chunk['data']->getData());
        }
        return $bytesWritten;
    }

    /**
     * Saves download to the physical file.
     * @param string $filename name of the physical file.
     * @return integer number of written bytes.
     */
    public function toFile($filename)
    {
        $filename = Yii::getAlias($filename);
        FileHelper::createDirectory(dirname($filename));
        return $this->toStream(fopen($filename, 'w+'));
    }

    /**
     * Returns a string of the bytes in the associated file.
     * @return string file content.
     */
    public function toString()
    {
        $result = '';
        foreach ($this->getChunkCursor() as $chunk) {
            $result .= $chunk['data']->getData();
        }
        return $result;
    }

    /**
     * Return part of a file.
     * @param integer $start reading start position.
     * If non-negative, the returned string will start at the start'th position in file, counting from zero.
     * If negative, the returned string will start at the start'th character from the end of file.
     * @param integer $length number of bytes to read.
     * If given and is positive, the string returned will contain at most length characters beginning from start (depending on the length of file).
     * If given and is negative, then that many characters will be omitted from the end of file (after the start position has been calculated when a start is negative).
     * @return string|false the extracted part of file or `false` on failure
     */
    public function substr($start, $length)
    {
        $document = $this->getDocument();

        if ($start < 0) {
            $start = max($document['length'] + $start, 0);
        }

        if ($start > $document['length']) {
            return false;
        }

        if ($length < 0) {
            $length = $document['length'] - $start + $length;
            if ($length < 0) {
                return false;
            }
        }

        $chunkSize = $document['chunkSize'];

        $startChunkNumber = floor($start / $chunkSize);

        $chunkIterator = $this->getChunkIterator();

        if (!$chunkIterator->valid()) {
            // invalid iterator state - recreate iterator
            // unable to use `rewind` due to error "Cursors cannot rewind after starting iteration"
            $chunkIterator = $this->getChunkIterator(true);
        }

        if ($chunkIterator->key() > $startChunkNumber) {
            // unable to go back by iterator
            // unable to use `rewind` due to error "Cursors cannot rewind after starting iteration"
            $chunkIterator = $this->getChunkIterator(true);
        }

        $result = '';

        $chunkDataOffset = $start - $startChunkNumber * $chunkSize;
        while ($chunkIterator->valid()) {
            if ($chunkIterator->key() >= $startChunkNumber) {
                $chunk = $chunkIterator->current();
                $data = $chunk['data']->getData();

                $readLength = min($chunkSize - $chunkDataOffset, $length);

                $result .= StringHelper::byteSubstr($data, $chunkDataOffset, $readLength);

                $length -= $readLength;
                if ($length <= 0) {
                    break;
                }

                $chunkDataOffset = 0;
            }

            $chunkIterator->next();
        }

        return $result;
    }

    // Compatibility with `MongoGridFSFile` :

    /**
     * Alias of [[toString()]] method.
     * @return string file content.
     */
    public function getBytes()
    {
        return $this->toString();
    }

    /**
     * Alias of [[toFile()]] method.
     * @param string $filename name of the physical file.
     * @return integer number of written bytes.
     */
    public function write($filename)
    {
        return $this->toFile($filename);
    }

    /**
     * @return resource file stream resource.
     */
    public function getResource()
    {
        // TODO : create stream wrapper
    }
}