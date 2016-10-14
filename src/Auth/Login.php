<?php
namespace Bokbasen\Auth;

use Psr\Cache\CacheItemPoolInterface;
use Http\Client\HttpClient;
use Bokbasen\Auth\Exceptions\BokbasenAuthException;

/**
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */
/**
 * Authentication class to use against Bokbasen's web services
 *
 * @link https://bokbasen.jira.com/wiki/display/api/Authentication+Service API Documentation
 * @license https://opensource.org/licenses/MIT
 */
class Login
{
    use \Bokbasen\Http\HttpMethodsTrait;

    /**
     *
     * @var string
     */
    protected $tgt;

    /**
     *
     * @var \Psr\Cache\CacheItemPoolInterface
     */
    protected $tgtCache;

    /**
     * Number of minutes a TGT should be cached
     *
     * @var int
     */
    protected $tgtExpireMinutes = self::DEFAULT_TGT_EXPIRE_TIME_MINUTES;

    /**
     *
     * @var string
     */
    protected $username;

    /**
     *
     * @var string
     */
    protected $password;

    /**
     *
     * @var bool
     */
    protected $reAuthAttempted;

    const URL_PROD = 'https://login.boknett.no/v1/tickets';

    const URL_TEST = 'https://login.boknett.webbe.no/v1/tickets';

    const HEADER_TGT = 'Boknett-TGT';

    const BEARER_NAME = 'Boknett';

    const HTTP_HEADER_DATE_FORMAT = 'D, d M Y H:i:s e';

    const DEFAULT_TGT_EXPIRE_TIME_MINUTES = 110;

    const CACHE_ITEM_KEY = 'bokbasen.tgt';

    /**
     *
     * @param string $username            
     * @param string $password            
     * @param CacheItemPoolInterface $tgtCache            
     * @param string $url            
     * @param HttpClient $httpClient            
     */
    public function __construct($username, $password, CacheItemPoolInterface $tgtCache = null, $url = self::URL_PROD, HttpClient $httpClient = null)
    {
        $this->url = $url;
        $this->tgtCache = $tgtCache;
        
        $this->setHttpClient($httpClient);
        $this->username = $username;
        $this->password = $password;
        $this->reAuthAttempted = false;
    }

    /**
     * Check if reauthetication is attempted, used to ensure that only one attempt is made for reauthetication
     *
     * @return boolean
     */
    public function isReAuthAttempted()
    {
        return $this->reAuthAttempted;
    }

    /**
     * Rerun authentication (will force new TGT, regardless of cache)
     */
    public function reAuthenticate()
    {
        $this->reAuthAttempted = true;
        $this->authenticate();
    }

    /**
     *
     * @return string
     */
    public function getTgt()
    {
        if (is_null($this->tgt)) {
            $this->setTGT();
        }
        return $this->tgt;
    }

    /**
     *
     * @param int $tgtExpireMinutes            
     */
    public function setTgtExpireMinutes($tgtExpireMinutes)
    {
        $this->tgtExpireMinutes = (int) $tgtExpireMinutes;
    }

    /**
     * Invalidates current TGT for future use
     */
    public function logout()
    {
        if (! is_null($this->tgt)) {
            $this->getMessageFactory()->createRequest('DELETE', $this->url . '/' . $this->tgt);
            unset($this->tgt);
        }
    }

    /**
     * Get authorzation headers as array
     *
     * @return array
     */
    public function getAuthHeadersAsArray()
    {
        if (is_null($this->tgt)) {
            $this->setTGT();
        }
        
        return [
            'Authorization' => self::BEARER_NAME . ' ' . $this->tgt,
            'Date' => gmdate(self::HTTP_HEADER_DATE_FORMAT)
        ];
    }

    /**
     * Populate $this->tgt either frmo cache or execute call to auth server
     */
    protected function setTGT()
    {
        if (! $this->isCachedTGT()) {
            $this->authenticate($this->username, $this->password);
        }
    }

    /**
     * Check if TGT is cached and if cache is valid, will set $this->tgt to cached value if true
     *
     * @return bool
     */
    protected function isCachedTGT()
    {
        if (is_null($this->tgtCache)) {
            return false;
        } else {
            $cachedItem = $this->tgtCache->getItem(self::CACHE_ITEM_KEY);
            if (! $cachedItem->isHit()) {
                return false;
            } else {
                $this->tgt = $cachedItem->get();
                return ! empty($this->tgt);
            }
        }
    }

    /**
     * Authorize user and store TGT
     *
     * @throws BokbasenAuthException
     * @return void
     */
    public function authenticate()
    {
        $request = $this->getMessageFactory()->createRequest('POST', $this->url, [], http_build_query([
            'username' => $this->username,
            'password' => $this->password
        ]));
        
        $response = $this->httpClient->sendRequest($request);
        
        if ($response->getStatusCode() != 201) {
            throw new BokbasenAuthException('Ticket not created. HTTP: ' . $response->getStatusCode() . ' Body:' . $response->getBody());
        }
        
        $this->tgt = $response->getHeaderLine(self::HEADER_TGT);
        
        if (! is_null($this->tgtCache)) {
            $tgtCacheItem = $this->tgtCache->getItem(self::CACHE_ITEM_KEY);
            $tgtCacheItem->set($this->tgt);
            $tgtCacheItem->expiresAfter($this->tgtExpireMinutes * 60);
            if ($this->tgtCache->save($tgtCacheItem) === false) {
                throw new BokbasenAuthException('Saving of cache failed.');
            }
        }
    }

    /**
     * If no TGT cache is defined, then destruct will perform a HTTP DELETE call to clear the TGT
     */
    public function __destruct()
    {
        if (! empty($this->tgt) && empty($this->tgtCache)) {
            $this->logout();
            unset($this->tgt);
        }
    }
}