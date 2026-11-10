# Signature images (Outbreak Group Report)

The Outbreak Group Report PDF prints a signature cell for each person named
under "Analyzed By / Reported By" and "Verified By".

Nothing in BDSIS stores a signature image -- there is no column for one on
`commondb.user_profile_table` and no upload screen -- so the report looks for a
file here instead.

Name the file after the person's full name as it appears in their user profile,
lower case, with every run of non-alphanumeric characters replaced by a single
hyphen:

    Ugyen Chophel    -> ugyen-chophel.png
    Kinley Gyem      -> kinley-gyem.png
    Rinzin Wangchuk  -> rinzin-wangchuk.png

`.png`, `.jpg` and `.jpeg` are accepted, in that order. Around 300x90 px on a
transparent or white background reproduces well; the report scales the image to
30px tall.

With no matching file the cell is printed as an empty ruled box to be signed by
hand, which is how every other BDSIS report handles signatures. Adding or
removing a file changes the next PDF generated; nothing needs to be deployed.
