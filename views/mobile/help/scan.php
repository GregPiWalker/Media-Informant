<p class="help-kicker">Help</p>
<h1>Scan</h1>
<p>A video scan looks up titles on TMDB, then Grok if Grok is enabled, for files already in the catalog. Save new folders in Config first so they can be indexed. The scan then runs in stages. Each stage can stop early, and the settings below change which files move forward and which service is asked. Open it from the scan icon in the header, beside the wrench.</p>
<nav class="help-onpage" aria-label="On this page">
  <a href="#scan-start">How a scan starts</a>
  <a href="#scan-files">Which files are included</a>
  <a href="#scan-lookup">Lookup</a>
  <a href="#scan-grok">Grok</a>
  <a href="#scan-finish">Finish and stop</a>
  <a href="#scan-settings">Settings</a>
  <a href="#scan-rescan">Re-scan</a>
  <a href="#scan-match">Match title</a>
</nav>

<h2 id="scan-start">How a scan starts</h2>
<p>Indexing and scanning are different steps.</p>
<ul>
  <li>Saving video folders in Config walks the disk and adds new files. A new file is unidentified until a scan looks it up. Excluded paths are left out. A source that is not mounted stays in the catalog and is skipped until the drive is back.</li>
  <li>Start a Video scan on the Scan page looks up files that are already indexed. It does not walk the disk for new files.</li>
  <li>Re-scan on a TV show, a season or episode group, or a movie folder runs the same lookup, limited to the files in that group.</li>
</ul>
<p>Only one scan runs at a time. Starting another shows that a scan is already running and does not replace it. Music has a scan page, and its start button stays off.</p>

<h2 id="scan-files">Which files are included</h2>
<p>Before any lookup, the scan keeps only files that match the Scan page options, still exist on disk, and sit on a source that is mounted. Everything else is left as it is.</p>
<p>Unidentified and unmatched are easy to mix up:</p>
<ul>
  <li><strong>Unidentified</strong> means the file has not been looked up yet.</li>
  <li><strong>Unmatched</strong> means a lookup already ran and did not save a confident match. The detail page says there was no confident match.</li>
</ul>
<p>The Advanced checkboxes on the Scan page are:</p>
<ul>
  <li><strong>Unidentified</strong> and <strong>Unmatched</strong> are on by default.</li>
  <li><strong>Matched (Grok)</strong> and <strong>Matched (Direct)</strong> are off. Turn one on only when those saved matches should be looked up again.</li>
  <li>A title you matched yourself is never looked up again.</li>
  <li>A title matched from RiffTrax or MST3K is kept until you use Clear Matches.</li>
</ul>
<p>If nothing in the library, or nothing in the group you re-scanned, passes those rules, the scan stops immediately. The log and the scan panel name the options that were on, how many files were skipped, and which checkbox or Clear Matches action would include them. The catalog is not rewritten. A folder or show page stays open so you can read that message.</p>

<h2 id="scan-lookup">Lookup</h2>
<p>Included files are looked up a few at a time so the page can keep updating. The service for each movie depends on the folder it lives in.</p>
<ul>
  <li>A folder named RiffTrax uses RiffTrax until you save a different service with Configure Scan.</li>
  <li>A folder named MST3K or Mystery Science Theater 3000 uses MST3K until you save a different service. A file named “MST3K - Title” or “MST3K: Title” also uses MST3K when its folder has no saved service. That choice wins over a RiffTrax folder name.</li>
  <li>Any other movie folder uses TMDB until you save a service on that folder.</li>
  <li>A saved choice applies to every movie in the folder, including movies that still have an older service stored on the file. A folder you already saved as RiffTrax stays on RiffTrax until you save MST3K or TMDB.</li>
  <li>Saving TMDB forces TMDB and does not try RiffTrax or MST3K for those files.</li>
  <li>TV shows and seasons do not have Configure Scan. Their re-scan uses TMDB.</li>
  <li>A saved service the scanner does not know is logged, and that file is left unmatched.</li>
</ul>
<h3>TMDB</h3>
<p>TMDB runs only when a TMDB API key is saved in Config. Without a key, a file that is not using RiffTrax or MST3K stays unidentified.</p>
<ul>
  <li>A single confident movie hit is saved as Matched (Direct), with the poster, overview, year, and genres from TMDB.</li>
  <li>Several hits, or no usable hit, do not pick a title. The file stays unmatched and the candidates are kept. Those files can go to Grok in the next stage.</li>
  <li>A TV show is searched once for the show folder. A season is loaded once. Later episodes in that folder reuse the show and match the episode number or episode title. They do not each search for the show again.</li>
  <li>Files that are parts of one movie share one lookup.</li>
  <li>A file can copy a match already saved for the same title and year. A folder that is using RiffTrax or MST3K does not copy a TMDB match. A folder saved as TMDB does not copy a RiffTrax or MST3K match.</li>
</ul>
<h3>RiffTrax</h3>
<p>Two RiffTrax paths exist.</p>
<ul>
  <li>On a library scan, a file is treated as RiffTrax when its first folder is named RiffTrax, or its filename starts with “RiffTrax - ”. An exact page hit is saved as a RiffTrax match without asking Grok. A catalog search hit is applied when the title is close. A partial catalog hit is queued for Grok only when Use Grok in this scan is on. With that toggle off, a close catalog title is still saved, and a partial hit stays unmatched.</li>
  <li>Re-scan of a folder whose saved service is RiffTrax, including a folder that is not named RiffTrax, searches RiffTrax for every included file. One exact or close title is saved. If that misses and an xAI key is configured, Grok chooses immediately. This path does not wait for a Grok batch, and Use Grok in this scan does not turn it off. If Grok is not configured, or Grok does not choose, the file stays unmatched, a warning is written to the log, and the scan continues. It does not fall through to TMDB.</li>
</ul>
<h3>MST3K</h3>
<p>MST3K uses the same RiffTrax site, with its own pages. Configure Scan lists it beside TMDB and RiffTrax. A direct guess tries https://www.rifftrax.com/mst3k-{slug} before the plain slug. For “The Crawling Hand” the first address is https://www.rifftrax.com/mst3k-the-crawling-hand. If those pages miss, the scan searches https://www.rifftrax.com/catalog/mst3k?search= and keeps only MST3K titles. The log names the slug URLs and that catalog address when nothing matched.</p>
<ul>
  <li>On a library scan, a file is treated as MST3K when its first folder is named MST3K or Mystery Science Theater 3000, or its filename starts with “MST3K - ” or “MST3K:”. The prefix is removed before the search, so “MST3K - The Crawling Hand” is looked up as “The Crawling Hand”. An exact page hit is saved as an MST3K match without asking Grok. A catalog search hit is applied when the title is close. A partial catalog hit is queued for Grok only when Use Grok in this scan is on. With that toggle off, a close catalog title is still saved, and a partial hit stays unmatched.</li>
  <li>Re-scan of a folder whose service is MST3K searches MST3K for every included file. One exact or close title is saved. If that misses and an xAI key is configured, Grok chooses immediately. Use Grok in this scan does not turn that off. A miss stays unmatched, is written to the log, and is not sent to TMDB.</li>
</ul>

<h2 id="scan-grok">Grok</h2>
<p>Grok is the second stage for titles TMDB did not settle. It needs an xAI key in Config. Use Grok in this scan must be on for this stage. You can change that toggle during a scan. The request already in flight finishes, then the new setting applies.</p>
<ul>
  <li>Unresolved TMDB titles are queued. Grok runs when 25 are waiting, and again at the end of the file list if any remain.</li>
  <li>Grok saves a match only when it is confident. That result is Matched (Grok). A low-confidence or declined choice leaves the file unmatched, with its candidates, so you can use Match title.</li>
  <li>Pause after each Grok batch waits for Continue before the next batch or the rest of the TMDB work.</li>
  <li>With the toggle off, the scan stays on TMDB and on RiffTrax or MST3K direct hits. Queued leftovers are not sent to Grok.</li>
</ul>
<p>Match title, and the immediate choice during a RiffTrax or MST3K folder re-scan, ask Grok whenever the xAI key is configured. They do not read Use Grok in this scan.</p>

<h2 id="scan-finish">Finish and stop</h2>
<p>The catalog is saved as the scan goes and again when it finishes. The scan panel reports how many titles were found and, when Grok ran, how many it matched and an estimated cost.</p>
<p>Stop saves the matches already made and leaves the remaining files in their previous state. The log records a stopped scan as a warning and a finished scan as a normal line.</p>
<p>After Re-scan on a group or folder page, the page reloads when the scan looked files up. It stays put when no files were selected, so the explanation remains on screen.</p>

<h2 id="scan-settings">Settings that change the outcome</h2>
<dl class="help-defs">
  <div>
    <dt>Video folders and categories</dt>
    <dd>Decide which directories are indexed and whether a tree is treated as movies or as a TV show with seasons. Saving Config is what adds new unidentified files.</dd>
  </div>
  <div>
    <dt>Excluded paths</dt>
    <dd>Those paths are not indexed, so a scan never sees them.</dd>
  </div>
  <div>
    <dt>TMDB API key and language</dt>
    <dd>The key allows TMDB lookup. The language is sent with TMDB requests and affects the text that comes back. No key means files that are not using RiffTrax or MST3K stay unidentified.</dd>
  </div>
  <div>
    <dt>xAI Grok key</dt>
    <dd>Allows the Grok stage, Match title choices, and the immediate choice on a RiffTrax or MST3K folder re-scan. Without a key, those steps are skipped.</dd>
  </div>
  <div>
    <dt>Use Grok in this scan</dt>
    <dd>Turns the queued Grok stage on or off, including catalog hits from an ordinary RiffTrax or MST3K library scan. It does not affect Match title or a RiffTrax or MST3K folder re-scan.</dd>
  </div>
  <div>
    <dt>Pause after each Grok batch</dt>
    <dd>Holds the scan for Continue after each batch. Both Grok toggles apply without stopping the scan.</dd>
  </div>
  <div>
    <dt>Files to look up</dt>
    <dd>The four Advanced checkboxes. They are read when the scan starts. A scan that selects nothing tells you which box would have included the files.</dd>
  </div>
  <div>
    <dt>Configure Scan</dt>
    <dd>On a movie-folder page only. Saves TMDB, RiffTrax, or MST3K for that folder and for the movies inside it. Re-scan and Match title then use that service.</dd>
  </div>
  <div>
    <dt>Clear Matches</dt>
    <dd>Drops saved matches and returns those files to unidentified. It does not clear the folder’s scan service. Use it before a new service should replace titles that are already matched, then scan with Unidentified turned on.</dd>
  </div>
</dl>

<h2 id="scan-rescan">Re-scan a folder or a show</h2>
<p>Re-scan uses the same file checkboxes as a full scan. A folder set to RiffTrax or MST3K still skips movies that are already matched unless Matched (Direct), Matched (Grok), or Clear Matches makes them eligible. Unmatched movies are included only when Unmatched is on. That is why a folder can be set to RiffTrax or MST3K and a re-scan can still look up nothing.</p>
<p>A TV show re-scan looks up episodes with TMDB. It does not use the movie-folder scan service.</p>

<h2 id="scan-match">Match title</h2>
<p>Match title on one movie uses the same service as that movie’s folder. A saved folder service wins. If the folder has no saved service, MST3K detection is used when the folder or filename matches, then a folder named RiffTrax searches RiffTrax, and other movies search TMDB. The Match title screen can still switch among TMDB, RiffTrax, and MST3K for that search.</p>
<p>When Grok can be called, it chooses from the results, including a single hit. If it cannot choose, the list stays on the page for you to pick. A choice you save is manual, and later scans keep it.</p>
