<html>
    <head>

    </head>
<body>
    <script type="application/javascript">
        var allData = "<?php echo json_encode($data); ?>";
    </script>
    <div class="row">
        <div class="col-lg-12 content-right">
            <h3>Better Stats View</h3>
            Survey ID: <?php echo $surveyinfo['sid']; ?>
        </div>
        <?php print_r($data); ?>
    </div>
</body>
</html>