<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license. For more information, see
 * <http://www.doctrine-project.org>.
 */

namespace Doctrine\Search\Event;

use Doctrine\Search\SearchManager;
use Doctrine\Common\EventArgs;

/**
 * Provides event arguments for the postFlush event.
 *
 * @link        www.doctrine-project.org
 * @since       2.0
 * @author      Daniel Freudenberger <df@rebuy.de>
 */
class PostFlushEventArgs extends EventArgs
{
    /**
     * @var SearchManager
     */
    private $sm;

    /**
     * Constructor.
     *
     * @param SearchManager $sm
     */
    public function __construct(SearchManager $sm)
    {
        $this->sm = $sm;
    }

    /**
     * Retrieve associated SearchManager.
     *
     * @return SearchManager
     */
    public function getSearchManager()
    {
        return $this->sm;
    }
}
