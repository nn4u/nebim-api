<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@4.0.0/dist/css/bootstrap.min.css" integrity="sha384-Gn5384xqQ1aoWXA+058RXPxPg6fy4IWvTNh0E263XmFcJlSAwiGgFAW/dAiS6JXm" crossorigin="anonymous">
    <link rel="stylesheet" href="{{ URL::asset('assets/css/frontendcustom-css.css') }}" />
    <style>
      .stor {
          margin-top: 13rem;
      }
      input.stor-field {
          height: 50px;
          width: 100%;
      }

      button.btn.buttn {
          width: 100%;
          height: 50px;
          background: #008060;
      }

      button.btn.buttn {
          width: 100%;
          height: 50px;
          font-size: 21px;
          letter-spacing: 1px;
          color: #fff;
          background: #008060;
      }
      ::placeholder {
          color: #008060!important;
          padding: 15px;
      }
      input::placeholder {
    color: #008060;
}
    </style>
</head>
<body>
<div class="container" >   
    <div class="row">
        <div class="col-sm-6 m-auto offset-6">
            <div class="stor">
                <form action="{{ url('authenticate') }}" method="POST">
                    @csrf
                    <div class="form-group">
                       <input placeholder="store.myshopify.com" class="stor-field" type="text" name="shop" Required>
                    </div> 
                    <div class="form-group">
                       <button class="btn buttn" type="submit" name="submit">Install</button>
                    </div> 
                </form>
           </div>
       </div>
    </div>
</div>
</body>
</html>


